<?php

namespace App\Support;

use App\Models\User;

/**
 * Role-based access to school API areas (see config/daruno_access.php).
 */
class TenantAccess
{
    public const READ = 'read';

    public const WRITE = 'write';

    public static function roleName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }
        $user->loadMissing('role');

        return $user->role?->name;
    }

    public static function isPlatformAdmin(?User $user): bool
    {
        return self::roleName($user) === config('daruno_access.platform_role', 'super_admin');
    }

    public static function modeForMethod(string $method): string
    {
        return in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)
            ? self::READ
            : self::WRITE;
    }

    /**
     * Whether the user may read or write the given area.
     */
    public static function can(?User $user, string $area, string $mode = self::READ): bool
    {
        if (! $user) {
            return false;
        }
        if (self::isPlatformAdmin($user)) {
            return true;
        }

        $def = config("daruno_access.areas.{$area}");
        $role = self::roleName($user);
        if (! is_array($def) || ! $role) {
            return false;
        }

        $writers = $def['write'] ?? [];
        if (in_array($role, $writers, true)) {
            return true;
        }

        return $mode === self::READ && in_array($role, $def['read'] ?? [], true);
    }

    /**
     * Area => {read, write} map for the portal (hides navigation the user cannot use).
     *
     * @return array<string, array{read: bool, write: bool}>
     */
    public static function permissionsFor(?User $user): array
    {
        $out = [];
        foreach (array_keys(config('daruno_access.areas', [])) as $area) {
            $out[$area] = [
                'read' => self::can($user, $area, self::READ),
                'write' => self::can($user, $area, self::WRITE),
            ];
        }

        return $out;
    }
}
