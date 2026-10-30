<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use Tests\TestCase;

/**
 * Sign in with Shengena: the identity service is faked with a locally generated
 * RSA key, so ID token signatures, issuer, audience and nonce are really checked.
 */
class OidcLoginTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://id.example.test/realms/shengena';

    private string $privateKey;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        config()->set('services.oidc', [
            'enabled' => true,
            'issuer' => self::ISSUER,
            'internal_issuer' => '',
            'client_id' => 'shengena-school',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://api.example.test/api/v1/auth/oidc/callback',
            'portal_url' => 'https://portal.example.test',
        ]);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->privateKey = $pem;
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $jwk = ['kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'use' => 'sig',
            'n' => $this->b64url($rsa['n']), 'e' => $this->b64url($rsa['e'])];

        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'/protocol/openid-connect/auth',
                'token_endpoint' => self::ISSUER.'/protocol/openid-connect/token',
                'jwks_uri' => self::ISSUER.'/protocol/openid-connect/certs',
                'end_session_endpoint' => self::ISSUER.'/protocol/openid-connect/logout',
            ]),
            self::ISSUER.'/protocol/openid-connect/certs' => Http::response(['keys' => [$jwk]]),
        ]);

        $school = School::query()->create(['name' => 'Alpha School', 'code' => 'ALPHA', 'status' => 'active']);
        $this->user = User::query()->create([
            'school_id' => $school->id,
            'role_id' => Role::query()->where('name', 'school_admin')->value('id'),
            'name' => 'Alpha Admin',
            'email' => 'Admin@Alpha.test',
            'password' => 'Password123!',
        ]);
    }

    public function test_first_sign_in_links_the_account_with_the_same_verified_email(): void
    {
        $fragment = $this->signIn(['sub' => 'kc-123', 'email' => 'admin@alpha.test', 'email_verified' => true]);

        $this->assertArrayHasKey('sso_code', $fragment);
        $this->assertSame('kc-123', $this->user->fresh()->external_id);

        $this->postJson('/api/v1/auth/oidc/exchange', ['code' => $fragment['sso_code']])
            ->assertOk()
            ->assertJsonPath('user.email', 'Admin@Alpha.test')
            ->assertJsonPath('audience', 'school')
            ->assertJsonStructure(['access_token', 'sso' => ['logout_url']]);

        // The one-time code cannot be used again.
        $this->postJson('/api/v1/auth/oidc/exchange', ['code' => $fragment['sso_code']])->assertUnauthorized();
    }

    public function test_later_sign_ins_use_the_linked_identity_even_if_the_email_changes(): void
    {
        $this->user->forceFill(['external_id' => 'kc-123'])->save();

        $fragment = $this->signIn(['sub' => 'kc-123', 'email' => 'new@alpha.test', 'email_verified' => true]);

        $this->assertArrayHasKey('sso_code', $fragment);
    }

    public function test_unverified_email_is_not_linked(): void
    {
        $fragment = $this->signIn(['sub' => 'kc-999', 'email' => 'admin@alpha.test', 'email_verified' => false]);

        $this->assertSame(['sso_error' => 'no_account'], $fragment);
        $this->assertNull($this->user->fresh()->external_id);
    }

    public function test_an_account_already_linked_to_another_identity_is_not_taken_over(): void
    {
        $this->user->forceFill(['external_id' => 'kc-original'])->save();

        $fragment = $this->signIn(['sub' => 'kc-other', 'email' => 'admin@alpha.test', 'email_verified' => true]);

        $this->assertSame(['sso_error' => 'no_account'], $fragment);
        $this->assertSame('kc-original', $this->user->fresh()->external_id);
    }

    public function test_tokens_with_a_wrong_nonce_audience_or_signature_are_rejected(): void
    {
        $claims = ['sub' => 'kc-123', 'email' => 'admin@alpha.test', 'email_verified' => true];

        $this->assertSame(['sso_error' => 'failed'], $this->signIn($claims, nonce: 'not-the-nonce'));
        $this->assertSame(['sso_error' => 'failed'], $this->signIn($claims, audience: 'another-app'));

        $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($otherKey, $forged);
        $this->assertSame(['sso_error' => 'failed'], $this->signIn($claims, signingKey: $forged));

        $this->assertNull($this->user->fresh()->external_id);
    }

    public function test_an_unknown_or_reused_state_is_refused(): void
    {
        $response = $this->get('/api/v1/auth/oidc/callback?state=made-up&code=abc');

        $this->assertSame(['sso_error' => 'expired'], $this->fragment($response->headers->get('Location')));
    }

    public function test_a_demo_sign_up_gets_an_account_in_the_configured_school(): void
    {
        config()->set('services.oidc.provision_school_code', 'ALPHA');
        config()->set('services.oidc.apps', ['hr' => ['name' => 'HR & payroll', 'url' => 'https://demo-hr.example.test']]);

        $fragment = $this->signIn(['sub' => 'kc-demo', 'email' => 'visitor@example.test', 'email_verified' => true,
            'name' => 'Asha Mwita', 'groups' => ['/systems/school', '/systems/hr']]);

        $user = User::query()->where('external_id', 'kc-demo')->firstOrFail();
        $this->assertSame('visitor@example.test', $user->email);
        $this->assertSame('Asha Mwita', $user->name);
        $this->assertSame('school_admin', $user->role->name);
        $this->assertSame($this->user->school_id, $user->school_id);

        $this->postJson('/api/v1/auth/oidc/exchange', ['code' => $fragment['sso_code']])
            ->assertOk()
            ->assertJsonPath('sso.apps', [[
                'key' => 'hr', 'name' => 'HR & payroll', 'url' => 'https://demo-hr.example.test/login?sso=1',
            ]]);
    }

    public function test_no_account_is_created_without_the_school_system(): void
    {
        config()->set('services.oidc.provision_school_code', 'ALPHA');

        $fragment = $this->signIn(['sub' => 'kc-demo', 'email' => 'visitor@example.test', 'email_verified' => true,
            'groups' => ['/systems/erp']]);

        $this->assertSame(['sso_error' => 'no_account'], $fragment);
        $this->assertFalse(User::query()->where('email', 'visitor@example.test')->exists());
    }

    public function test_no_account_is_created_when_provisioning_is_off(): void
    {
        $fragment = $this->signIn(['sub' => 'kc-demo', 'email' => 'visitor@example.test', 'email_verified' => true,
            'groups' => ['/systems/school']]);

        $this->assertSame(['sso_error' => 'no_account'], $fragment);
        $this->assertFalse(User::query()->where('email', 'visitor@example.test')->exists());
    }

    public function test_an_address_already_in_use_is_never_given_a_second_account(): void
    {
        config()->set('services.oidc.provision_school_code', 'ALPHA');
        $this->user->forceFill(['external_id' => 'kc-original'])->save();

        $fragment = $this->signIn(['sub' => 'kc-other', 'email' => 'admin@alpha.test', 'email_verified' => true,
            'groups' => ['/systems/school']]);

        $this->assertSame(['sso_error' => 'no_account'], $fragment);
        $this->assertSame(1, User::query()->whereRaw('LOWER(email) = ?', ['admin@alpha.test'])->count());
    }

    public function test_sso_routes_are_hidden_when_disabled(): void
    {
        config()->set('services.oidc.enabled', false);

        $this->getJson('/api/v1/auth/oidc/config')->assertOk()->assertJson(['enabled' => false]);
        $this->get('/api/v1/auth/oidc/redirect')->assertNotFound();
    }

    /**
     * Run the browser round trip and return the portal URL fragment.
     *
     * @param  array<string, mixed>  $claims
     * @return array<string, string>
     */
    private function signIn(array $claims, ?string $nonce = null, ?string $audience = null, ?string $signingKey = null): array
    {
        $location = $this->get('/api/v1/auth/oidc/redirect')->assertRedirect()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);

        $idToken = $this->idToken($claims + ['nonce' => $nonce ?? $query['nonce']], $audience, $signingKey);
        Http::fake([self::ISSUER.'/protocol/openid-connect/token' => Http::response(['id_token' => $idToken])]);

        $callback = $this->get('/api/v1/auth/oidc/callback?'.http_build_query(['state' => $query['state'], 'code' => 'auth-code']));

        return $this->fragment($callback->headers->get('Location'));
    }

    /** @return array<string, string> */
    private function fragment(string $location): array
    {
        $this->assertStringStartsWith('https://portal.example.test/login#', $location);
        parse_str((string) parse_url($location, PHP_URL_FRAGMENT), $fragment);

        return $fragment;
    }

    /** @param array<string, mixed> $claims */
    private function idToken(array $claims, ?string $audience, ?string $signingKey): string
    {
        $now = new \DateTimeImmutable;
        $builder = (new Builder(new JoseEncoder, ChainedFormatter::default()))
            ->withHeader('kid', 'k1')
            ->issuedBy(self::ISSUER)
            ->permittedFor($audience ?? 'shengena-school')
            ->relatedTo($claims['sub'])
            ->issuedAt($now)
            ->expiresAt($now->modify('+5 minutes'));
        foreach ($claims as $name => $value) {
            if ($name !== 'sub') {
                $builder = $builder->withClaim($name, $value);
            }
        }

        return $builder->getToken(new Sha256, InMemory::plainText($signingKey ?? $this->privateKey))->toString();
    }

    private function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
