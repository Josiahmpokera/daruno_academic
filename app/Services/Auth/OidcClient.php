<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * OpenID Connect client for the Shengena identity service (Keycloak).
 *
 * Authorization code flow with PKCE. The ID token is verified against the
 * issuer's published RS256 keys, its issuer, audience, lifetime and nonce.
 */
class OidcClient
{
    public function __construct(private readonly array $config)
    {
    }

    public static function fromConfig(): self
    {
        return new self(config('services.oidc'));
    }

    public function enabled(): bool
    {
        return $this->config['enabled']
            && $this->config['issuer'] !== ''
            && ! empty($this->config['client_secret'])
            && ! empty($this->config['redirect_uri']);
    }

    public function portalUrl(string $path = ''): string
    {
        return $this->config['portal_url'].$path;
    }

    public function authorizationUrl(string $state, string $nonce, string $codeVerifier): string
    {
        return $this->discovery()['authorization_endpoint'].'?'.http_build_query([
            'client_id' => $this->config['client_id'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'redirect_uri' => $this->config['redirect_uri'],
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::base64Url(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
        ]);
    }

    /** Where the portal sends the browser to end the Shengena session too. */
    public function logoutUrl(): string
    {
        return $this->discovery()['end_session_endpoint'].'?'.http_build_query([
            'client_id' => $this->config['client_id'],
            'post_logout_redirect_uri' => $this->portalUrl('/login'),
        ]);
    }

    /**
     * Exchange the authorization code and return the verified ID token claims.
     *
     * @return array<string, mixed>
     */
    public function claimsForCode(string $code, string $codeVerifier, string $nonce): array
    {
        $response = Http::asForm()
            ->timeout(10)
            ->withBasicAuth($this->config['client_id'], $this->config['client_secret'])
            ->post($this->backchannel($this->discovery()['token_endpoint']), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->config['redirect_uri'],
                'code_verifier' => $codeVerifier,
            ]);

        if (! $response->successful() || ! is_string($response->json('id_token'))) {
            throw new RuntimeException('Token exchange failed: HTTP '.$response->status());
        }

        return $this->verifyIdToken($response->json('id_token'), $nonce);
    }

    /** @return array<string, mixed> */
    public function verifyIdToken(string $jwt, string $nonce): array
    {
        $token = (new Parser(new JoseEncoder()))->parse($jwt);
        if (! $token instanceof Plain) {
            throw new RuntimeException('Unexpected ID token type');
        }

        $kid = $token->headers()->get('kid');
        if ($token->headers()->get('alg') !== 'RS256' || ! is_string($kid)) {
            throw new RuntimeException('ID token must be RS256 with a key id');
        }

        $validator = new Validator();
        $validator->assert(
            $token,
            new SignedWith(new Sha256(), InMemory::plainText($this->publicKey($kid))),
            new IssuedBy($this->config['issuer']),
            new PermittedFor($this->config['client_id']),
            new LooseValidAt(self::clock(), new \DateInterval('PT60S')),
        );

        $claims = $token->claims()->all();
        if (! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new RuntimeException('ID token nonce mismatch');
        }

        return $claims;
    }

    /** @return array<string, mixed> */
    private function discovery(): array
    {
        return Cache::remember('oidc:discovery:'.md5($this->config['issuer']), 3600, function () {
            $response = Http::timeout(10)->get(
                $this->backchannel($this->config['issuer']).'/.well-known/openid-configuration'
            );
            if (! $response->successful() || $response->json('issuer') !== $this->config['issuer']) {
                throw new RuntimeException('OIDC discovery failed for the configured issuer');
            }

            return $response->json();
        });
    }

    private function publicKey(string $kid): string
    {
        $find = function (bool $refresh) use ($kid): ?string {
            $cacheKey = 'oidc:jwks:'.md5($this->config['issuer']);
            if ($refresh) {
                Cache::forget($cacheKey);
            }
            $keys = Cache::remember($cacheKey, 3600, fn () => Http::timeout(10)
                ->get($this->backchannel($this->discovery()['jwks_uri']))
                ->throw()
                ->json('keys', []));

            foreach ($keys as $key) {
                if (($key['kid'] ?? null) === $kid && ($key['kty'] ?? null) === 'RSA') {
                    return self::rsaPem($key['n'], $key['e']);
                }
            }

            return null;
        };

        // Keys rotate: on an unknown key id, fetch the key set once more.
        return $find(false) ?? $find(true) ?? throw new RuntimeException('Unknown ID token signing key');
    }

    /** Reach the issuer's endpoints at the internal address when one is configured. */
    private function backchannel(string $url): string
    {
        $internal = $this->config['internal_issuer'] ?? '';

        return $internal !== '' && str_starts_with($url, $this->config['issuer'])
            ? $internal.substr($url, strlen($this->config['issuer']))
            : $url;
    }

    private static function clock(): ClockInterface
    {
        return new class implements ClockInterface
        {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** PEM public key from a JWK's base64url modulus and exponent. */
    public static function rsaPem(string $n, string $e): string
    {
        $decode = fn (string $v) => base64_decode(strtr($v, '-_', '+/').str_repeat('=', (4 - strlen($v) % 4) % 4));
        $length = function (int $len): string {
            if ($len < 0x80) {
                return chr($len);
            }
            $bytes = ltrim(pack('N', $len), "\0");

            return chr(0x80 | strlen($bytes)).$bytes;
        };
        $integer = function (string $bytes) use ($length): string {
            $bytes = ltrim($bytes, "\0");
            if (ord($bytes[0]) > 0x7F) {
                $bytes = "\0".$bytes;
            }

            return "\x02".$length(strlen($bytes)).$bytes;
        };

        $rsaKey = $integer($decode($n)).$integer($decode($e));
        $rsaKey = "\x30".$length(strlen($rsaKey)).$rsaKey;
        $bitString = "\x03".$length(strlen($rsaKey) + 1)."\x00".$rsaKey;
        $algorithm = "\x30\x0D\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00";
        $spki = "\x30".$length(strlen($algorithm.$bitString)).$algorithm.$bitString;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }
}
