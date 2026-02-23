<?php

namespace App\Http\Middleware;

use App\Models\School;
use Closure;
use Illuminate\Http\Request;

class SetCurrentSchool
{
    public function handle(Request $request, Closure $next)
    {
        $schoolId = $request->header('X-School-ID');
        $schoolCode = $request->header('X-School-Code');

        $school = null;

        if ($schoolId) {
            $school = School::query()->whereKey($schoolId)->first();
        } elseif ($schoolCode) {
            $school = School::query()->where('code', $schoolCode)->first();
        }

        if ($school) {
            $request->attributes->set('school', $school);
        }

        return $next($request);
    }
}

