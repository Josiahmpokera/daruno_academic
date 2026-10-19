<?php

namespace App\Http\Middleware;

use App\Support\TenantAccess;
use Closure;
use Illuminate\Http\Request;

/**
 * Route middleware `access:{area}` — role check against config/daruno_access.php.
 * Read methods need read access; all other methods need write access.
 */
class EnsureAreaAccess
{
    public function handle(Request $request, Closure $next, string $area)
    {
        $user = $request->user();
        $mode = TenantAccess::modeForMethod($request->method());

        if (TenantAccess::can($user, $area, $mode)) {
            return $next($request);
        }

        $label = config("daruno_access.areas.{$area}.label", $area);
        $message = $mode === TenantAccess::WRITE
            ? "Your role cannot make changes in {$label}."
            : "Your role does not have access to {$label}.";

        return response()->json([
            'success' => false,
            'error' => $message,
            'data' => (object) [],
        ], 403);
    }
}
