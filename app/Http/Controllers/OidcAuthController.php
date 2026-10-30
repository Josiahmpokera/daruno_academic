<?php

namespace App\Http\Controllers;

use App\Models\LoginHistory;
use App\Models\Role;
use App\Models\School;
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
 * A person signs in to the portal account that already has their verified email,
 * which is then linked to their identity. The one exception is a demo sign-up
 * from the website: when services.oidc.provision_school_code is set and the token
 * grants /systems/school, a person without an account gets one in that school.
 */
class OidcAuthController extends AuthController
{
    private const STATE_TTL = 600;

    private const CODE_TTL = 60;

    /** Identity service groups that grant each Shengena system. */
    private const SYSTEM_GROUPS = ['school' => '/systems/school', 'erp' => '/systems/erp', 'hr' => '/systems/hr'];

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
        Cache::put("oidc:code:$code", ['user' => $user->id, 'apps' => $this->otherApps($claims)], self::CODE_TTL);

        return $this->backToPortal($oidc, ['sso_code' => $code]);
    }

    public function exchange(Request $request, OidcClient $oidc)
    {
        abort_unless($oidc->enabled(), 404);
        $request->validate(['code' => ['required', 'string', 'size:64']]);

        $pending = Cache::pull('oidc:code:'.$request->input('code'));
        $user = is_array($pending) ? User::query()->find($pending['user']) : null;
        if (! $user) {
            return response()->json(['message' => 'This sign-in link has expired. Please try again.'], 401);
        }

        $token = auth('api')->login($user);
        $this->recordLogin($request, $user, $user->email, 'success');

        $response = $this->respondWithToken($token, $user);
        $response->setData($response->getData(true) + ['sso' => [
            'logout_url' => $oidc->logoutUrl(),
            'apps' => $pending['apps'],
        ]]);

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

            return $user;
        }

        // Only an address no portal account uses at all (linked or not) gets a new one.
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return null;
        }

        return $this->provisionDemoUser($claims, $subject, $email);
    }

    /** @param array<string, mixed> $claims */
    private function provisionDemoUser(array $claims, string $subject, string $email): ?User
    {
        $schoolCode = (string) config('services.oidc.provision_school_code', '');
        if ($schoolCode === '' || ! $this->hasSystem($claims, 'school')) {
            return null;
        }
        $school = School::query()->where('code', $schoolCode)->first();
        $role = Role::query()->where('name', config('services.oidc.provision_role') ?: 'school_admin')->first();
        if (! $school || ! $role || $role->name === 'super_admin') {
            Log::warning('SSO provisioning is misconfigured', ['school' => $schoolCode]);

            return null;
        }

        $name = trim((string) ($claims['name'] ?? '')) ?: $email;
        $user = User::query()->create([
            'school_id' => $school->id,
            'role_id' => $role->id,
            'name' => Str::limit($name, 120, ''),
            'email' => $email,
            // Never used: this person signs in through the identity service.
            'password' => Str::password(40),
        ]);
        $user->forceFill(['external_id' => $subject])->save();
        Log::info('SSO account created', ['user_id' => $user->id, 'school_id' => $school->id]);

        return $user;
    }

    /** @param array<string, mixed> $claims */
    private function hasSystem(array $claims, string $system): bool
    {
        $groups = $claims['groups'] ?? [];

        return is_array($groups) && in_array(self::SYSTEM_GROUPS[$system], $groups, true);
    }

    /**
     * The other Shengena systems this person may use, for the portal's app menu.
     *
     * @param  array<string, mixed>  $claims
     * @return list<array{key: string, name: string, url: string}>
     */
    private function otherApps(array $claims): array
    {
        $apps = [];
        foreach ((array) config('services.oidc.apps') as $key => $app) {
            if (($app['url'] ?? '') !== '' && $this->hasSystem($claims, $key)) {
                $apps[] = ['key' => $key, 'name' => $app['name'] ?? $key, 'url' => $app['url'].'/login?sso=1'];
            }
        }

        return $apps;
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
