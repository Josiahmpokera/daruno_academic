<?php

namespace App\Http\Controllers\Concerns;

use App\Models\School;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ResolvesSchool
{
    protected function school(Request $request): School
    {
        $school = $request->attributes->get('school');

        if ($school instanceof School) {
            return $school;
        }

        $user = $request->user();
        if ($user && $user->school_id) {
            $school = School::query()->find($user->school_id);
            if ($school) {
                $request->attributes->set('school', $school);

                return $school;
            }
        }

        throw new HttpException(400, 'School context required. Send X-School-ID or link user to a school.');
    }

    protected function schoolId(Request $request): int
    {
        return (int) $this->school($request)->id;
    }
}
