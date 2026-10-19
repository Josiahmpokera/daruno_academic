<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Support\TenantAccess;
use Closure;
use Illuminate\Http\Request;

/**
 * Resolves the school (tenant) for the request and enforces isolation.
 *
 * - School users are always pinned to their own school. An X-School-ID /
 *   X-School-Code header naming any other school is rejected (403).
 * - Platform super admins may pick a school with the header (support access).
 * - Users of suspended / rejected schools are locked out.
 */
class SetCurrentSchool
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            return $this->deny('Unauthenticated', 401);
        }

        $headerId = trim((string) $request->header('X-School-ID', ''));
        $headerCode = trim((string) $request->header('X-School-Code', ''));

        if (TenantAccess::isPlatformAdmin($user)) {
            $school = null;
            if ($headerId !== '') {
                $school = ctype_digit($headerId) ? School::query()->find((int) $headerId) : null;
            } elseif ($headerCode !== '') {
                $school = School::query()->where('code', $headerCode)->first();
            }

            if (($headerId !== '' || $headerCode !== '') && ! $school) {
                return $this->deny('School not found.', 404);
            }

            if ($school) {
                $request->attributes->set('school', $school);
            }

            return $next($request);
        }

        if (! $user->school_id) {
            return $this->deny('Your account is not linked to a school.', 403);
        }

        $school = School::query()->find($user->school_id);
        if (! $school) {
            return $this->deny('Your school could not be found.', 403);
        }

        $headerMismatch = ($headerId !== '' && $headerId !== (string) $school->id)
            || ($headerCode !== '' && strcasecmp($headerCode, (string) $school->code) !== 0);
        if ($headerMismatch) {
            return $this->deny('You do not have access to this school.', 403);
        }

        if (in_array($school->status, config('daruno_access.blocked_school_statuses', []), true)) {
            return $this->deny('This school account is '.$school->status.'. Contact Shengena support.', 403);
        }

        $request->attributes->set('school', $school);

        return $next($request);
    }

    protected function deny(string $message, int $status)
    {
        return response()->json([
            'success' => false,
            'error' => $message,
            'data' => (object) [],
        ], $status);
    }
}
