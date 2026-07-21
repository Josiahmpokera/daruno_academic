<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolIdCardSetting;
use App\Models\Student;
use App\Models\StudentIdCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Industrial student identity: ID cards, QR verification tokens, barcodes.
 */
class StudentIdentityController extends Controller
{
    use ApiResponds, ResolvesSchool;

    /** Built-in print templates (TAMISEMI school ID style). */
    public static function templates(): array
    {
        return [
            [
                'key' => 'classic_green',
                'name' => 'Classic Green',
                'description' => 'Shengena green header — clean school standard',
                'preview_colors' => ['#0B3D2E', '#FFFFFF', '#F5F5F4'],
                'style' => 'classic',
            ],
            [
                'key' => 'modern_navy',
                'name' => 'Modern Navy',
                'description' => 'Navy band with white body — corporate modern',
                'preview_colors' => ['#1E3A5F', '#FFFFFF', '#E2E8F0'],
                'style' => 'modern',
            ],
            [
                'key' => 'tanzania_pride',
                'name' => 'Tanzania Pride',
                'description' => 'Green · yellow · blue accent inspired by the national flag',
                'preview_colors' => ['#1EB53A', '#FCD116', '#00A3DD'],
                'style' => 'flag',
            ],
            [
                'key' => 'minimal_slate',
                'name' => 'Minimal Slate',
                'description' => 'Low-ink slate design for bulk printing',
                'preview_colors' => ['#334155', '#F8FAFC', '#CBD5E1'],
                'style' => 'minimal',
            ],
            [
                'key' => 'premium_gold',
                'name' => 'Premium Gold',
                'description' => 'Dark card with gold trim — formal occasions',
                'preview_colors' => ['#1C1917', '#D4AF37', '#FAFAF9'],
                'style' => 'premium',
            ],
        ];
    }

    public function templatesIndex()
    {
        return $this->success(['templates' => self::templates()]);
    }

    public function settingsShow(Request $request)
    {
        try {
            $settings = $this->settingsFor($this->schoolId($request));

            return $this->success(['settings' => $settings]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function settingsUpdate(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'default_template' => ['sometimes', 'string', 'max:40'],
                'card_prefix' => ['sometimes', 'string', 'max:20'],
                'validity_years' => ['sometimes', 'integer', 'min:1', 'max:10'],
                'front_footer' => ['nullable', 'string', 'max:120'],
                'back_note' => ['nullable', 'string', 'max:255'],
                'show_photo_placeholder' => ['nullable', 'boolean'],
                'show_blood_group' => ['nullable', 'boolean'],
                'show_guardian' => ['nullable', 'boolean'],
            ]);

            $keys = array_column(self::templates(), 'key');
            if (isset($data['default_template']) && ! in_array($data['default_template'], $keys, true)) {
                return $this->error('Unknown template key', 422);
            }

            $settings = $this->settingsFor($schoolId);
            $settings->fill($data)->save();

            return $this->success(['settings' => $settings->fresh()], 'Identity settings saved');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function cardsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $query = StudentIdCard::query()
                ->where('school_id', $schoolId)
                ->with([
                    'student:id,first_name,middle_name,last_name,admission_number,gender,status,blood_group',
                    'student.currentEnrollment.classroom:id,name',
                ])
                ->orderByDesc('id');

            if ($request->integer('student_id')) {
                $query->where('student_id', $request->integer('student_id'));
            }
            if ($request->string('status')->toString() !== '') {
                $query->where('status', $request->string('status')->toString());
            }
            if ($request->string('template_key')->toString() !== '') {
                $query->where('template_key', $request->string('template_key')->toString());
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 40))));
            $items = collect($page->items())->map(fn (StudentIdCard $c) => $this->transformCard($c));

            return $this->success([
                'cards' => $items,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
                'summary' => [
                    'active' => StudentIdCard::query()->where('school_id', $schoolId)->where('status', 'active')->count(),
                    'revoked' => StudentIdCard::query()->where('school_id', $schoolId)->where('status', 'revoked')->count(),
                    'total' => StudentIdCard::query()->where('school_id', $schoolId)->count(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Issue or re-issue ID cards for one or many students.
     */
    public function cardsIssue(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'student_ids' => ['required', 'array', 'min:1', 'max:200'],
                'student_ids.*' => ['integer'],
                'template_key' => ['nullable', 'string', 'max:40'],
                'expires_on' => ['nullable', 'date'],
                'reissue' => ['nullable', 'boolean'],
            ]);

            $settings = $this->settingsFor($schoolId);
            $template = $data['template_key'] ?? $settings->default_template;
            $keys = array_column(self::templates(), 'key');
            if (! in_array($template, $keys, true)) {
                return $this->error('Unknown template key', 422);
            }

            $school = School::query()->findOrFail($schoolId);
            $issued = [];
            $skipped = [];

            DB::transaction(function () use ($schoolId, $data, $template, $settings, $school, $request, &$issued, &$skipped) {
                foreach ($data['student_ids'] as $studentId) {
                    $student = Student::query()
                        ->where('school_id', $schoolId)
                        ->find($studentId);
                    if (! $student) {
                        $skipped[] = ['student_id' => $studentId, 'reason' => 'not_found'];
                        continue;
                    }

                    $existing = StudentIdCard::query()
                        ->where('school_id', $schoolId)
                        ->where('student_id', $student->id)
                        ->where('status', 'active')
                        ->first();

                    if ($existing && empty($data['reissue'])) {
                        $issued[] = $this->transformCard($existing->load([
                            'student.currentEnrollment.classroom',
                        ]));
                        continue;
                    }

                    if ($existing && ! empty($data['reissue'])) {
                        $existing->status = 'reissued';
                        $existing->save();
                    }

                    $token = Str::lower(Str::random(40));
                    $cardNumber = $this->nextCardNumber($schoolId, $settings->card_prefix ?? 'SID');
                    $barcode = $student->admission_number
                        ? preg_replace('/\s+/', '', strtoupper((string) $student->admission_number))
                        : $cardNumber;

                    $payload = $this->buildQrPayload($school, $student, $cardNumber, $token);

                    $expires = $data['expires_on']
                        ?? now()->addYears((int) ($settings->validity_years ?: 1))->toDateString();

                    $card = StudentIdCard::query()->create([
                        'school_id' => $schoolId,
                        'student_id' => $student->id,
                        'card_number' => $cardNumber,
                        'template_key' => $template,
                        'verification_token' => $token,
                        'barcode_value' => $barcode,
                        'qr_payload' => $payload,
                        'status' => 'active',
                        'issued_on' => now()->toDateString(),
                        'expires_on' => $expires,
                        'issued_by' => $request->user()?->id,
                        'meta' => [
                            'school_code' => $school->code,
                            'admission_number' => $student->admission_number,
                        ],
                    ]);

                    $issued[] = $this->transformCard($card->load([
                        'student.currentEnrollment.classroom',
                    ]));
                }
            });

            return $this->success([
                'cards' => $issued,
                'skipped' => $skipped,
                'count' => count($issued),
            ], count($issued).' card(s) ready', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function cardsShow(Request $request, int $id)
    {
        try {
            $card = StudentIdCard::query()
                ->where('school_id', $this->schoolId($request))
                ->with([
                    'student.currentEnrollment.classroom',
                    'student.guardians',
                ])
                ->findOrFail($id);

            return $this->success([
                'card' => $this->transformCard($card, true),
                'school' => $this->schoolBrief($this->school($request)),
                'template' => collect(self::templates())->firstWhere('key', $card->template_key),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function cardsUpdate(Request $request, int $id)
    {
        try {
            $card = StudentIdCard::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            $data = $request->validate([
                'template_key' => ['sometimes', 'string', 'max:40'],
                'status' => ['sometimes', 'in:active,revoked,expired'],
                'expires_on' => ['nullable', 'date'],
            ]);

            if (isset($data['template_key'])) {
                $keys = array_column(self::templates(), 'key');
                if (! in_array($data['template_key'], $keys, true)) {
                    return $this->error('Unknown template key', 422);
                }
            }

            $card->fill($data)->save();

            return $this->success([
                'card' => $this->transformCard($card->fresh()->load([
                    'student.currentEnrollment.classroom',
                ])),
            ], 'Card updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function cardsMarkPrinted(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'card_ids' => ['required', 'array', 'min:1'],
                'card_ids.*' => ['integer'],
            ]);

            $updated = StudentIdCard::query()
                ->where('school_id', $schoolId)
                ->whereIn('id', $data['card_ids'])
                ->get();

            foreach ($updated as $card) {
                $card->print_count = (int) $card->print_count + 1;
                $card->last_printed_at = now();
                $card->save();
            }

            return $this->success([
                'updated' => $updated->count(),
            ], 'Print count updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Roster for QR / barcode / ID printing with live card data.
     */
    public function roster(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $settings = $this->settingsFor($schoolId);
            $school = $this->school($request);

            $query = Student::query()
                ->where('school_id', $schoolId)
                ->with(['currentEnrollment.classroom:id,name', 'guardians'])
                ->orderBy('first_name')
                ->orderBy('last_name');

            if ($request->string('status')->toString() !== '') {
                $query->where('status', $request->string('status')->toString());
            } else {
                $query->where('status', 'active');
            }
            if ($request->integer('classroom_id')) {
                $cid = $request->integer('classroom_id');
                $query->whereHas('enrollments', function ($e) use ($cid) {
                    $e->where('classroom_id', $cid)->where('status', 'active');
                });
            }
            $q = $request->string('q')->toString();
            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('admission_number', 'like', "%{$q}%");
                });
            }

            $perPage = min(48, max(6, $request->integer('per_page', 12)));

            // Summary against full filtered set (before page slice)
            $filteredIds = (clone $query)->pluck('id');
            $totalFiltered = $filteredIds->count();
            $withCardCount = StudentIdCard::query()
                ->where('school_id', $schoolId)
                ->where('status', 'active')
                ->whereIn('student_id', $filteredIds)
                ->distinct()
                ->count('student_id');

            $page = $query->paginate($perPage);
            $students = collect($page->items());

            $cardsByStudent = StudentIdCard::query()
                ->where('school_id', $schoolId)
                ->where('status', 'active')
                ->whereIn('student_id', $students->pluck('id'))
                ->get()
                ->keyBy('student_id');

            $rows = $students->map(function (Student $s) use ($cardsByStudent, $settings, $school) {
                $card = $cardsByStudent->get($s->id);
                $admission = $s->admission_number ?: ('TMP-'.$s->id);
                $barcode = $card?->barcode_value ?: preg_replace('/\s+/', '', strtoupper((string) $admission));
                $qr = $card?->qr_payload ?: json_encode([
                    'v' => 1,
                    'type' => 'shengena.student',
                    'school' => $school->code,
                    'adm' => $admission,
                    'sid' => $s->id,
                ], JSON_UNESCAPED_SLASHES);

                $primaryGuardian = $s->guardians->sortByDesc('is_primary')->first();

                return [
                    'student_id' => $s->id,
                    'full_name' => $s->full_name,
                    'first_name' => $s->first_name,
                    'last_name' => $s->last_name,
                    'admission_number' => $s->admission_number,
                    'gender' => $s->gender,
                    'blood_group' => $s->blood_group,
                    'status' => $s->status,
                    'classroom' => $s->currentEnrollment?->classroom ? [
                        'id' => $s->currentEnrollment->classroom->id,
                        'name' => $s->currentEnrollment->classroom->name,
                    ] : null,
                    'guardian_name' => $primaryGuardian?->full_name,
                    'card' => $card ? $this->transformCard($card) : null,
                    'has_card' => (bool) $card,
                    'barcode_value' => $barcode,
                    'qr_payload' => $qr,
                    'suggested_template' => $card?->template_key ?? $settings->default_template,
                ];
            });

            return $this->success([
                'students' => $rows,
                'school' => $this->schoolBrief($school),
                'settings' => $settings,
                'templates' => self::templates(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                ],
                'summary' => [
                    'total' => $totalFiltered,
                    'with_card' => $withCardCount,
                    'without_card' => max(0, $totalFiltered - $withCardCount),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Public / staff verification of a card token.
     */
    public function verify(Request $request, string $token)
    {
        try {
            $card = StudentIdCard::query()
                ->where('verification_token', $token)
                ->with(['student.currentEnrollment.classroom', 'school'])
                ->first();

            if (! $card) {
                return $this->error('Card not found or invalid', 404);
            }

            $valid = $card->status === 'active'
                && (! $card->expires_on || ! $card->expires_on->isPast());

            return $this->success([
                'valid' => $valid,
                'status' => $card->status,
                'card_number' => $card->card_number,
                'issued_on' => optional($card->issued_on)?->toDateString(),
                'expires_on' => optional($card->expires_on)?->toDateString(),
                'student' => [
                    'full_name' => $card->student?->full_name,
                    'admission_number' => $card->student?->admission_number,
                    'classroom' => $card->student?->currentEnrollment?->classroom?->name,
                    'status' => $card->student?->status,
                ],
                'school' => [
                    'name' => $card->school?->name,
                    'code' => $card->school?->code,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    protected function settingsFor(int $schoolId): SchoolIdCardSetting
    {
        return SchoolIdCardSetting::query()->firstOrCreate(
            ['school_id' => $schoolId],
            [
                'default_template' => 'classic_green',
                'card_prefix' => 'SID',
                'validity_years' => 1,
                'front_footer' => 'Property of the school — if found please return',
                'back_note' => 'Present this card at the gate. Report loss immediately.',
                'show_photo_placeholder' => true,
                'show_blood_group' => true,
                'show_guardian' => false,
            ]
        );
    }

    protected function nextCardNumber(int $schoolId, string $prefix): string
    {
        $year = now()->format('Y');
        $count = StudentIdCard::query()->where('school_id', $schoolId)->count() + 1;
        $base = strtoupper($prefix).'-'.$year.'-'.str_pad((string) $count, 5, '0', STR_PAD_LEFT);
        // Collision guard
        while (StudentIdCard::query()->where('school_id', $schoolId)->where('card_number', $base)->exists()) {
            $count++;
            $base = strtoupper($prefix).'-'.$year.'-'.str_pad((string) $count, 5, '0', STR_PAD_LEFT);
        }

        return $base;
    }

    protected function buildQrPayload(School $school, Student $student, string $cardNumber, string $token): string
    {
        // Compact machine-readable payload for scanners / phone QR
        return json_encode([
            'v' => 1,
            'type' => 'shengena.student_id',
            'school' => $school->code ?: (string) $school->id,
            'card' => $cardNumber,
            'adm' => $student->admission_number,
            'sid' => $student->id,
            'tok' => $token,
            'verify' => url('/api/v1/public/id-cards/verify/'.$token),
        ], JSON_UNESCAPED_SLASHES);
    }

    protected function schoolBrief(School $school): array
    {
        return [
            'id' => $school->id,
            'name' => $school->name,
            'code' => $school->code,
            'motto' => $school->motto,
            'logo_url' => $school->logo_url,
            'region' => $school->region,
            'district' => $school->district,
            'emis_code' => $school->emis_code,
        ];
    }

    protected function transformCard(StudentIdCard $card, bool $detailed = false): array
    {
        $student = $card->student;
        $row = [
            'id' => $card->id,
            'student_id' => $card->student_id,
            'card_number' => $card->card_number,
            'template_key' => $card->template_key,
            'barcode_value' => $card->barcode_value,
            'qr_payload' => $card->qr_payload,
            'verification_token' => $card->verification_token,
            'status' => $card->status,
            'issued_on' => optional($card->issued_on)?->toDateString(),
            'expires_on' => optional($card->expires_on)?->toDateString(),
            'print_count' => (int) $card->print_count,
            'last_printed_at' => optional($card->last_printed_at)?->toIso8601String(),
            'student' => $student ? [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'admission_number' => $student->admission_number,
                'gender' => $student->gender,
                'blood_group' => $student->blood_group,
                'status' => $student->status,
                'classroom' => $student->currentEnrollment?->classroom ? [
                    'id' => $student->currentEnrollment->classroom->id,
                    'name' => $student->currentEnrollment->classroom->name,
                ] : null,
            ] : null,
        ];

        if ($detailed && $student) {
            $g = $student->guardians?->sortByDesc('is_primary')->first();
            $row['student']['guardian_name'] = $g?->full_name;
            $row['student']['guardian_phone'] = $g?->phone;
        }

        return $row;
    }
}
