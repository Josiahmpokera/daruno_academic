<?php

namespace App\Services\Communication;

use App\Models\Staff;
use App\Models\StudentGuardian;

/**
 * Resolves an announcement audience to a de-duplicated contact list.
 *
 * audience: all | parents | staff | classes (parents of learners in classroom_ids)
 */
class Audience
{
    public const TYPES = ['all', 'parents', 'staff', 'classes'];

    /**
     * @param  list<int>  $classroomIds
     * @return list<array{type: string, id: int, name: string, email: ?string, phone: ?string}>
     */
    public static function resolve(int $schoolId, string $audience, array $classroomIds = []): array
    {
        $contacts = [];

        if (in_array($audience, ['all', 'parents', 'classes'], true)) {
            $q = StudentGuardian::query()
                ->where('school_id', $schoolId)
                ->whereHas('student', function ($s) use ($audience, $classroomIds) {
                    $s->where('status', 'active');
                    if ($audience === 'classes') {
                        $s->whereHas('enrollments', fn ($e) => $e
                            ->where('status', 'active')
                            ->whereIn('classroom_id', $classroomIds ?: [0]));
                    }
                })
                ->orderBy('id');

            foreach ($q->cursor() as $g) {
                $contacts[] = [
                    'type' => 'guardian',
                    'id' => (int) $g->id,
                    'name' => (string) $g->full_name,
                    'email' => self::clean($g->email),
                    'phone' => self::clean($g->phone),
                ];
            }
        }

        if (in_array($audience, ['all', 'staff'], true)) {
            $q = Staff::query()
                ->where('school_id', $schoolId)
                ->where('status', 'active')
                ->orderBy('id');

            foreach ($q->cursor() as $s) {
                $contacts[] = [
                    'type' => 'staff',
                    'id' => (int) $s->id,
                    'name' => (string) $s->full_name,
                    'email' => self::clean($s->email),
                    'phone' => self::clean($s->phone),
                ];
            }
        }

        // One person can be guardian of several learners: dedupe by email, then phone.
        $seen = [];
        $unique = [];
        foreach ($contacts as $c) {
            $key = $c['email'] ? 'e:'.strtolower($c['email']) : ($c['phone'] ? 'p:'.preg_replace('/\D+/', '', $c['phone']) : $c['type'].':'.$c['id']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $c;
        }

        return $unique;
    }

    /**
     * @param  list<array{type: string, email: ?string, phone: ?string}>  $contacts
     * @return array{total: int, guardians: int, staff: int, with_email: int, with_phone: int}
     */
    public static function summarize(array $contacts): array
    {
        return [
            'total' => count($contacts),
            'guardians' => count(array_filter($contacts, fn ($c) => $c['type'] === 'guardian')),
            'staff' => count(array_filter($contacts, fn ($c) => $c['type'] === 'staff')),
            'with_email' => count(array_filter($contacts, fn ($c) => ! empty($c['email']))),
            'with_phone' => count(array_filter($contacts, fn ($c) => ! empty($c['phone']))),
        ];
    }

    protected static function clean(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
