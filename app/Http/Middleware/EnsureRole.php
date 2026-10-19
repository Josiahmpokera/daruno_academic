<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if ($user) {
            $user->loadMissing('role');
        }

        if (! $user || ! $user->role || ! in_array($user->role->name, $roles, true)) {
            return response()->json([
                'success' => false,
                'error' => 'Forbidden',
                'data' => (object) [],
            ], 403);
        }

        return $next($request);
    }
}
