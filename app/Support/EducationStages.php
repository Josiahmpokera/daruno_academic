<?php

namespace App\Support;

/**
 * Canonical TAMISEMI education stages used across registration,
 * school profile, subjects, combinations, and class setup.
 */
class EducationStages
{
    public const PRE_PRIMARY = 'pre_primary';

    public const PRIMARY = 'primary';

    public const ORDINARY_LEVEL = 'ordinary_level';

    public const ADVANCED_LEVEL = 'advanced_level';

    /** @return list<string> */
    public static function keys(): array
    {
        return [
            self::PRE_PRIMARY,
            self::PRIMARY,
            self::ORDINARY_LEVEL,
            self::ADVANCED_LEVEL,
        ];
    }

    /**
     * @return list<array{value: string, label: string, short_label: string, description: string}>
     */
    public static function catalog(): array
    {
        return [
            [
                'value' => self::PRE_PRIMARY,
                'label' => 'Pre-primary',
                'short_label' => 'Pre-primary',
                'description' => 'Nursery / pre-primary (before Std I)',
            ],
            [
                'value' => self::PRIMARY,
                'label' => 'Primary (Std I–VII)',
                'short_label' => 'Primary',
                'description' => 'Primary school Standard 1 to Standard 7',
            ],
            [
                'value' => self::ORDINARY_LEVEL,
                'label' => 'O-Level (Form 1–4)',
                'short_label' => 'O-Level',
                'description' => 'Ordinary level secondary (CSEE)',
            ],
            [
                'value' => self::ADVANCED_LEVEL,
                'label' => 'A-Level (Form 5–6)',
                'short_label' => 'A-Level',
                'description' => 'Advanced level secondary (ACSEE combinations)',
            ],
        ];
    }

    /**
     * Normalize a list of stage keys; drops invalid entries.
     *
     * @param  mixed  $input
     * @return list<string>
     */
    public static function normalize(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $allowed = array_flip(self::keys());
        $out = [];
        foreach ($input as $v) {
            $key = is_string($v) ? trim($v) : '';
            if ($key !== '' && isset($allowed[$key]) && ! in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Human label list for education_level legacy string.
     *
     * @param  list<string>  $stages
     */
    public static function labelSummary(array $stages): string
    {
        $map = [];
        foreach (self::catalog() as $row) {
            $map[$row['value']] = $row['short_label'];
        }
        $labels = array_values(array_filter(array_map(
            fn ($k) => $map[$k] ?? null,
            $stages
        )));

        return $labels ? implode(' · ', $labels) : '';
    }

    /**
     * Map education_levels.code / name → canonical stage key.
     */
    public static function fromLevelCodeOrName(?string $code, ?string $name): ?string
    {
        $c = strtoupper(trim((string) $code));
        $n = strtolower(trim((string) $name));

        if (
            in_array($c, ['PP', 'PRE', 'PRE-P'], true)
            || str_contains($n, 'pre-primary')
            || str_contains($n, 'pre primary')
            || str_contains($n, 'nursery')
        ) {
            return self::PRE_PRIMARY;
        }

        if (
            in_array($c, ['PR', 'PR-L', 'PRI', 'PRIM'], true)
            || (str_contains($n, 'primary') && ! str_contains($n, 'pre'))
        ) {
            return self::PRIMARY;
        }

        if (
            in_array($c, ['O', 'OL', 'O-LEVEL', 'OLEVEL'], true)
            || str_contains($n, 'ordinary')
            || str_contains($n, 'o-level')
            || str_contains($n, 'o level')
        ) {
            return self::ORDINARY_LEVEL;
        }

        if (
            in_array($c, ['A', 'AL', 'A-LEVEL', 'ALEVEL'], true)
            || str_contains($n, 'advanced')
            || str_contains($n, 'a-level')
            || str_contains($n, 'a level')
        ) {
            return self::ADVANCED_LEVEL;
        }

        return null;
    }

    /**
     * Canonical level code used by academic seed / structure.
     */
    public static function levelCodeForStage(string $stage): ?string
    {
        return match ($stage) {
            self::PRE_PRIMARY => 'PP',
            self::PRIMARY => 'PR',
            self::ORDINARY_LEVEL => 'O',
            self::ADVANCED_LEVEL => 'A',
            default => null,
        };
    }

    /**
     * Default level name for a stage.
     */
    public static function levelNameForStage(string $stage): string
    {
        return match ($stage) {
            self::PRE_PRIMARY => 'Pre-Primary',
            self::PRIMARY => 'Primary',
            self::ORDINARY_LEVEL => 'Ordinary Level',
            self::ADVANCED_LEVEL => 'Advanced Level',
            default => $stage,
        };
    }
}
