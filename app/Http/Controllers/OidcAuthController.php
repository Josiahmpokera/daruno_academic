<?php

namespace App\Http\Controllers;

use App\Models\LoginHistory;
use App\Models\User;
use App\Services\Auth\OidcClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sign in with Shengena (single sign-on through the Shengena identity service).
 *
 * 1. GET  /auth/oidc/redirect  sends the browser to the identity service.
 * 2. GET  /auth/oidc/callback  verifies the result, finds the portal user and
 *    returns the browser to the portal with a one-time code in the URL fragment.
 * 3. POST /auth/oidc/exchange  swaps that code for the usual portal session.
 *
 * Accounts are never created here: a person signs in to the portal account that
 * already has their verified email, which is then linked to their identity.
 */
class OidcAuthController extends AuthController
{
    private const STATE_TTL = 600;

    private const CODE_TTL = 60;

    public function config(OidcClient $oidc)
    {
        return response()->json(['enabled' => $oidc->enabled()]);
    }

    public function redirect(OidcClient $oidc): RedirectResponse
    {
        abort_unless($oidc->enabled(), 404);

        $state = Str::random(48);
        $nonce = Str::random(48);
        $verifier = OidcClient::base64Url(random_bytes(48));
        Cache::put("oidc:state:$state", ['nonce' => $nonce, 'verifier' => $verifier], self::STATE_TTL);

        return redirect()->away($oidc->authorizationUrl($state, $nonce, $verifier));
    }

    public function callback(Request $request, OidcClient $oidc): RedirectResponse
    {
        abort_unless($oidc->enabled(), 404);

        $pending = Cache::pull('oidc:state:'.$request->query('state', ''));
        if (! is_array($pending)) {
            return $this->backToPortal($oidc, ['sso_error' => 'expired']);
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->backToPortal($oidc, ['sso_error' => 'cancelled']);
        }

        try {
            $claims = $oidc->claimsForCode((string) $request->query('code'), $pending['verifier'], $pending['nonce']);
        } catch (Throwable $e) {
            Log::warning('SSO sign-in failed', ['reason' => $e->getMessage()]);

            return $this->backToPortal($oidc, ['sso_error' => 'failed']);
        }

        $user = $this->findOrLinkUser($claims);
        if (! $user) {
            $this->recordLogin($request, null, (string) ($claims['email'] ?? ''), 'failed');

            return $this->backToPortal($oidc, ['sso_error' => 'no_account']);
        }

        $code = Str::random(64);
        Cache::put("oidc:code:$code", $user->id, self::CODE_TTL);

        return $this->backToPortal($oidc, ['sso_code' => $code]);
    }

    public function exchange(Request $request, OidcClient $oidc)
    {
        abort_unless($oidc->enabled(), 404);
        $request->validate(['code' => ['required', 'string', 'size:64']]);

        $user = User::query()->find(Cache::pull('oidc:code:'.$request->input('code')));
        if (! $user) {
            return response()->json(['message' => 'This sign-in link has expired. Please try again.'], 401);
        }

        $token = auth('api')->login($user);
        $this->recordLogin($request, $user, $user->email, 'success');

        $response = $this->respondWithToken($token, $user);
        $response->setData($response->getData(true) + ['sso' => ['logout_url' => $oidc->logoutUrl()]]);

        return $response;
    }

    /** @param array<string, mixed> $claims */
    private function findOrLinkUser(array $claims): ?User
    {
        $subject = (string) ($claims['sub'] ?? '');
        if ($subject === '') {
            return null;
        }

        $linked = User::query()->where('external_id', $subject)->first();
        if ($linked) {
            return $linked;
        }

        // First sign-in: link the account with the same verified email, once.
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if ($email === '' || ($claims['email_verified'] ?? false) !== true) {
            return null;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->whereNull('external_id')->first();
        if ($user) {
            $user->forceFill(['external_id' => $subject])->save();
        }

        return $user;
    }

    /** @param array<string, string> $params */
    private function backToPortal(OidcClient $oidc, array $params): RedirectResponse
    {
        // Fragment, not query: the code never reaches server logs or Referer headers.
        return redirect()->away($oidc->portalUrl('/login').'#'.http_build_query($params));
    }

    private function recordLogin(Request $request, ?User $user, string $email, string $status): void
    {
        try {
            LoginHistory::query()->create([
                'school_id' => $user?->school_id,
                'user_id' => $user?->id,
                'email' => $email,
                'status' => $status,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 250),
                'logged_in_at' => now(),
            ]);
        } catch (Throwable) {
        }
    }
}
