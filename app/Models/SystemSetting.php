<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    protected $fillable = [
        'school_id',
        'key',
        'value',
        'type',
    ];

    public const CACHE_KEY = 'platform.system_settings';

    /**
     * Default platform (super-admin) settings.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'app_name' => 'Shengena',
            'support_email' => 'support@shengena.app',
            'default_currency' => 'USD',
            'default_trial_days' => 15,
            'allow_self_registration' => true,
            'require_school_approval' => true,
            'jwt_ttl_minutes' => (int) config('jwt.ttl', 60),
            'password_min_length' => 8,
            'email_enabled' => true,
            'sms_enabled' => false,
            // Marketing website (public landing)
            'website_tagline' => 'One classroom, one system.',
            'website_subtitle' => 'Shengena gives private schools everything they need to manage students, staff, fees, exams, and parent communication — in one place.',
            'website_phone' => '',
            'website_address' => 'Dar es Salaam, Tanzania',
            'website_facebook' => '',
            'website_twitter' => '',
            'website_linkedin' => '',
            'website_instagram' => '',
            'website_copyright' => '© {year} Shengena. All rights reserved.',
            'website_footer_columns' => [
                [
                    'title' => 'Product',
                    'links' => [
                        ['label' => 'Features', 'href' => '/features'],
                        ['label' => 'Pricing', 'href' => '/#pricing'],
                        ['label' => 'Register school', 'href' => '/register-school'],
                        ['label' => 'Join a school', 'href' => '/apply'],
                    ],
                ],
                [
                    'title' => 'Company',
                    'links' => [
                        ['label' => 'About', 'href' => '/#about'],
                        ['label' => 'Contact', 'href' => '/#contact'],
                        ['label' => 'Support', 'href' => '/#support'],
                    ],
                ],
                [
                    'title' => 'Legal',
                    'links' => [
                        ['label' => 'Privacy', 'href' => '/#privacy'],
                        ['label' => 'Terms', 'href' => '/#terms'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function platformAll(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            $defaults = self::defaults();
            $rows = static::query()
                ->whereNull('school_id')
                ->get(['key', 'value', 'type']);

            foreach ($rows as $row) {
                if (! array_key_exists($row->key, $defaults)) {
                    continue;
                }
                $defaults[$row->key] = self::castValue($row->value, $row->type ?? self::inferType($defaults[$row->key]));
            }

            return $defaults;
        });
    }

    /**
     * Persist platform settings (school_id null). Merges with defaults.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function platformUpdate(array $input): array
    {
        $defaults = self::defaults();
        $allowed = array_keys($defaults);

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            $type = self::inferType($defaults[$key]);
            $stored = self::serializeValue($value, $type);

            static::query()->updateOrCreate(
                ['school_id' => null, 'key' => $key],
                ['value' => $stored, 'type' => $type],
            );
        }

        Cache::forget(self::CACHE_KEY);

        return self::platformAll();
    }

    protected static function inferType(mixed $default): string
    {
        return match (true) {
            is_bool($default) => 'boolean',
            is_int($default) => 'integer',
            is_float($default) => 'float',
            is_array($default) => 'json',
            default => 'string',
        };
    }

    protected static function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    protected static function serializeValue(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => json_encode($value),
            default => (string) $value,
        };
    }
}
