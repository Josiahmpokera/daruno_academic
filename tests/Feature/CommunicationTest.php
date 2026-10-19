<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\AcademicYear;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Communication\Publisher;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Mail\Events\MessageSent;
use Tests\TestCase;

class CommunicationTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected School $other;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array']);
        $this->seed(RolesTableSeeder::class);
        $this->school = School::query()->create(['name' => 'Alpha', 'code' => 'ALPHA', 'status' => 'active']);
        $this->other = School::query()->create(['name' => 'Beta', 'code' => 'BETA', 'status' => 'active']);
        $this->admin = $this->user('school_admin', $this->school);
    }

    protected function user(string $role, School $school): User
    {
        static $n = 0;
        $n++;

        return User::query()->create([
            'school_id' => $school->id,
            'role_id' => Role::query()->where('name', $role)->value('id'),
            'name' => "{$role} {$n}",
            'email' => "{$role}{$n}@example.test",
            'password' => 'Password123!',
        ]);
    }

    protected function learnerWithGuardian(School $school, ?Classroom $class = null, string $email = 'parent@example.test'): StudentGuardian
    {
        static $n = 0;
        $n++;
        $s = Student::query()->create(['school_id' => $school->id, 'first_name' => "Kid{$n}", 'last_name' => 'Test', 'status' => 'active']);
        if ($class) {
            $year = AcademicYear::query()->firstOrCreate(['school_id' => $school->id, 'name' => '2026']);
            Enrollment::query()->create([
                'school_id' => $school->id, 'student_id' => $s->id, 'classroom_id' => $class->id,
                'academic_year_id' => $year->id, 'status' => 'active', 'enrolled_at' => now()->toDateString(),
            ]);
        }

        return StudentGuardian::query()->create([
            'school_id' => $school->id, 'student_id' => $s->id, 'relationship' => 'mother',
            'full_name' => "Parent {$n}", 'phone' => '+25570000000'.$n, 'email' => $email ? str_replace('@', "{$n}@", $email) : null,
            'is_primary' => true,
        ]);
    }

    protected function as(User $u)
    {
        return $this->actingAs($u, 'api')->withHeaders(['Accept' => 'application/json']);
    }

    // ── Parent messaging ─────────────────────────────────────────

    public function test_start_conversation_reply_and_log_parent_reply(): void
    {
        $g = $this->learnerWithGuardian($this->school);

        $res = $this->as($this->admin)->postJson('/api/v1/communication/threads', [
            'guardian_id' => $g->id,
            'subject' => 'Homework follow-up',
            'body' => 'Please check the maths homework tonight.',
            'channels' => ['portal', 'email'],
        ])->assertStatus(201)->assertJson(['success' => true]);

        $threadId = $res->json('data.thread.id');
        $this->assertSame('email', $res->json('data.messages.0.channels.1'));
        $this->assertSame('sent', $res->json('data.messages.0.delivery.email.status'));
        $this->assertSame(1, NotificationLog::query()->where('channel', 'email')->where('recipient', $g->email)->count());

        $this->as($this->admin)->postJson("/api/v1/communication/threads/{$threadId}/messages", ['body' => 'Reminder: due Friday.'])
            ->assertStatus(201)
            ->assertJsonCount(2, 'data.messages');

        $this->as($this->admin)->postJson("/api/v1/communication/threads/{$threadId}/inbound", [
            'body' => 'Thank you, we will check.', 'channel' => 'phone',
        ])->assertStatus(201)
            ->assertJsonPath('data.messages.2.direction', 'inbound')
            ->assertJsonPath('data.thread.last_direction', 'inbound');

        $this->as($this->admin)->getJson('/api/v1/communication/threads?q=Homework')
            ->assertOk()->assertJsonCount(1, 'data.threads');

        $this->as($this->admin)->patchJson("/api/v1/communication/threads/{$threadId}", ['status' => 'closed'])
            ->assertOk()->assertJsonPath('data.thread.status', 'closed');
    }

    public function test_sms_is_refused_until_gateway_is_connected(): void
    {
        $g = $this->learnerWithGuardian($this->school);
        $payload = ['guardian_id' => $g->id, 'subject' => 'Fees', 'body' => 'Balance reminder', 'channels' => ['sms']];

        $this->as($this->admin)->postJson('/api/v1/communication/threads', $payload)
            ->assertStatus(422)->assertJsonPath('success', false);

        foreach (['sms_enabled' => '1', 'sms_api_key' => 'test-key', 'sms_provider' => 'beem'] as $k => $v) {
            SystemSetting::query()->create(['school_id' => $this->school->id, 'key' => $k, 'value' => $v]);
        }

        $res = $this->as($this->admin)->postJson('/api/v1/communication/threads', $payload)->assertStatus(201);
        // Never reported as "sent" — queued for the provider
        $this->assertSame('queued', $res->json('data.messages.0.delivery.sms.status'));
    }

    public function test_cannot_message_another_schools_guardian(): void
    {
        $foreign = $this->learnerWithGuardian($this->other);

        $this->as($this->admin)->postJson('/api/v1/communication/threads', [
            'guardian_id' => $foreign->id, 'subject' => 'Hi', 'body' => 'Hello',
        ])->assertStatus(422);

        $this->assertDatabaseCount('parent_threads', 0);
    }

    // ── Announcements ────────────────────────────────────────────

    public function test_publish_announcement_to_a_class_delivers_email(): void
    {
        $classA = Classroom::query()->create(['school_id' => $this->school->id, 'name' => 'Form 1 A']);
        $classB = Classroom::query()->create(['school_id' => $this->school->id, 'name' => 'Form 1 B']);
        $this->learnerWithGuardian($this->school, $classA);
        $this->learnerWithGuardian($this->school, $classA);
        $this->learnerWithGuardian($this->school, $classB);

        $this->as($this->admin)->getJson('/api/v1/communication/audience-preview?audience=classes&classroom_ids[]='.$classA->id)
            ->assertOk()->assertJsonPath('data.summary.guardians', 2);

        $sent = 0;
        Event::listen(MessageSent::class, function () use (&$sent) {
            $sent++;
        });

        $res = $this->as($this->admin)->postJson('/api/v1/communication/announcements', [
            'title' => 'Parents meeting',
            'body' => 'Form 1 A parents meeting on Friday at 10:00.',
            'audience' => 'classes',
            'classroom_ids' => [$classA->id],
            'channels' => ['portal', 'email'],
            'action' => 'publish',
        ])->assertStatus(201)
            ->assertJsonPath('data.item.status', 'published')
            ->assertJsonPath('data.item.recipients_count', 2);

        $id = $res->json('data.item.id');
        $this->assertSame(2, $sent, 'two class guardians receive the email');
        $this->assertSame(2, Announcement::query()->find($id)->delivery_summary['email']['sent']);

        // Published broadcasts cannot be deleted
        $this->as($this->admin)->deleteJson("/api/v1/communication/announcements/{$id}")->assertStatus(422);
    }

    public function test_class_audience_requires_classes_and_other_school_classes_are_rejected(): void
    {
        $base = ['title' => 'X', 'body' => 'Y', 'audience' => 'classes'];
        $this->as($this->admin)->postJson('/api/v1/communication/announcements', $base)->assertStatus(422);

        $foreignClass = Classroom::query()->create(['school_id' => $this->other->id, 'name' => 'Other']);
        $this->as($this->admin)->postJson('/api/v1/communication/announcements', $base + ['classroom_ids' => [$foreignClass->id]])
            ->assertStatus(422)
            ->assertJsonPath('error', 'The selected classroom_ids does not exist in this school.');
    }

    public function test_scheduled_announcement_publishes_when_due(): void
    {
        $this->learnerWithGuardian($this->school);
        $at = now()->addHour();

        $res = $this->as($this->admin)->postJson('/api/v1/communication/announcements', [
            'title' => 'Exam timetable', 'body' => 'Mock exams start Monday.', 'audience' => 'parents',
            'publish_at' => $at->toIso8601String(), 'action' => 'schedule',
        ])->assertStatus(201)->assertJsonPath('data.item.status', 'scheduled');

        $this->assertSame(0, Publisher::publishDue());
        $this->travelTo($at->copy()->addMinute());
        $this->assertSame(1, Publisher::publishDue());
        $this->assertSame('published', Announcement::query()->find($res->json('data.item.id'))->status);
    }

    // ── Notice board ─────────────────────────────────────────────

    public function test_notice_board_shows_only_live_notices(): void
    {
        $make = fn (array $a) => $this->as($this->admin)->postJson('/api/v1/communication/announcements', array_merge([
            'kind' => 'notice', 'title' => 'Notice', 'body' => 'Body', 'category' => 'general',
        ], $a))->assertStatus(201)->json('data.item.id');

        $make(['title' => 'Sports day', 'category' => 'sports', 'action' => 'publish', 'is_pinned' => true]);
        $make(['title' => 'Draft notice']);
        $expired = $make(['title' => 'Old notice', 'action' => 'publish']);
        Announcement::query()->whereKey($expired)->update(['expires_at' => now()->subDay()]);
        $make(['title' => 'Other school', 'action' => 'publish']); // same school, shows
        $this->as($this->user('school_admin', $this->other))->postJson('/api/v1/communication/announcements', [
            'kind' => 'notice', 'title' => 'Beta only', 'body' => 'x', 'action' => 'publish',
        ])->assertStatus(201);

        $titles = collect($this->as($this->admin)->getJson('/api/v1/communication/notice-board')->assertOk()->json('data.items'))->pluck('title');

        $this->assertSame(['Sports day', 'Other school'], $titles->all()); // pinned first; no draft/expired/foreign
    }

    // ── Roles ────────────────────────────────────────────────────

    public function test_teacher_can_message_parents_but_not_publish_announcements(): void
    {
        $teacher = $this->user('teacher', $this->school);
        $g = $this->learnerWithGuardian($this->school);

        $this->as($teacher)->postJson('/api/v1/communication/threads', [
            'guardian_id' => $g->id, 'subject' => 'Behaviour', 'body' => 'Great improvement this week.',
        ])->assertStatus(201);

        $this->as($teacher)->getJson('/api/v1/communication/notice-board')->assertOk();
        $this->as($teacher)->postJson('/api/v1/communication/announcements', [
            'title' => 'X', 'body' => 'Y', 'action' => 'publish',
        ])->assertStatus(403);

        $this->as($this->user('librarian', $this->school))->getJson('/api/v1/communication/threads')->assertStatus(403);
    }
}
