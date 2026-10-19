<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Role::query()->firstOrCreate(['name' => 'clerk'], ['label' => 'Clerk']);

        $this->schoolA = School::query()->create(['name' => 'Alpha School', 'code' => 'ALPHA', 'status' => 'active']);
        $this->schoolB = School::query()->create(['name' => 'Beta School', 'code' => 'BETA', 'status' => 'active']);
    }

    protected function userWithRole(string $role, ?School $school): User
    {
        static $n = 0;
        $n++;

        return User::query()->create([
            'school_id' => $school?->id,
            'role_id' => Role::query()->where('name', $role)->value('id'),
            'name' => "{$role} {$n}",
            'email' => "{$role}{$n}@example.test",
            'password' => 'Password123!',
        ]);
    }

    protected function as(User $user, array $headers = [])
    {
        return $this->actingAs($user, 'api')->withHeaders(['Accept' => 'application/json'] + $headers);
    }

    // ── Tenant isolation ─────────────────────────────────────────

    public function test_school_user_cannot_switch_school_with_header(): void
    {
        $admin = $this->userWithRole('school_admin', $this->schoolA);

        $this->as($admin, ['X-School-ID' => (string) $this->schoolB->id])
            ->getJson('/api/v1/students')
            ->assertStatus(403)
            ->assertJson(['success' => false]);

        $this->as($admin, ['X-School-Code' => 'BETA'])
            ->getJson('/api/v1/students')
            ->assertStatus(403);
    }

    public function test_school_user_reads_only_own_school_data(): void
    {
        $admin = $this->userWithRole('school_admin', $this->schoolA);
        Student::query()->create(['school_id' => $this->schoolA->id, 'first_name' => 'Asha', 'last_name' => 'A', 'admission_number' => 'A1']);
        Student::query()->create(['school_id' => $this->schoolB->id, 'first_name' => 'Baraka', 'last_name' => 'B', 'admission_number' => 'B1']);

        // Own school header (or none) is accepted
        $this->as($admin, ['X-School-ID' => (string) $this->schoolA->id])
            ->getJson('/api/v1/school/profile')
            ->assertOk()
            ->assertJsonPath('data.school.id', $this->schoolA->id);

        $studentB = Student::query()->where('school_id', $this->schoolB->id)->first();
        $this->as($admin)->getJson("/api/v1/students/{$studentB->id}")->assertStatus(404);
    }

    public function test_school_endpoint_rejects_other_school(): void
    {
        $admin = $this->userWithRole('school_admin', $this->schoolA);

        $this->as($admin)->getJson("/api/v1/schools/{$this->schoolA->id}")->assertOk();
        $this->as($admin)->getJson("/api/v1/schools/{$this->schoolB->id}")->assertStatus(403);
        $this->as($admin)->patchJson("/api/v1/schools/{$this->schoolB->id}", ['name' => 'Hacked'])->assertStatus(403);
        $this->assertSame('Beta School', $this->schoolB->fresh()->name);
    }

    public function test_ids_from_another_school_are_rejected(): void
    {
        $admin = $this->userWithRole('school_admin', $this->schoolA);
        $yearB = AcademicYear::query()->create(['school_id' => $this->schoolB->id, 'name' => '2026']);
        $classB = Classroom::query()->create(['school_id' => $this->schoolB->id, 'name' => 'Form 1 B']);
        $studentB = Student::query()->create(['school_id' => $this->schoolB->id, 'first_name' => 'Baraka', 'last_name' => 'B']);

        $this->as($admin)->postJson('/api/v1/students', [
            'first_name' => 'New',
            'last_name' => 'Learner',
            'classroom_id' => $classB->id,
            'academic_year_id' => $yearB->id,
        ])->assertStatus(422)->assertJsonPath('error', 'The selected classroom_id does not exist in this school.');

        $this->as($admin)->postJson('/api/v1/finance/payments', [
            'student_id' => $studentB->id,
            'amount' => 1000,
        ])->assertStatus(422)->assertJsonPath('error', 'The selected student_id does not exist in this school.');

        // Array keys are checked too
        $this->as($admin)->postJson('/api/v1/students/bulk-status', [
            'student_ids' => [$studentB->id],
            'status' => 'inactive',
        ])->assertStatus(422)->assertJsonPath('error', 'The selected student_ids does not exist in this school.');

        $this->assertSame(0, Student::query()->where('school_id', $this->schoolA->id)->count());
        $this->assertNotSame('inactive', $studentB->fresh()->status);

        // Positive control: the same request with own-school IDs succeeds
        $yearA = AcademicYear::query()->create(['school_id' => $this->schoolA->id, 'name' => '2026']);
        $classA = Classroom::query()->create(['school_id' => $this->schoolA->id, 'name' => 'Form 1 A']);
        $this->as($admin)->postJson('/api/v1/students', [
            'first_name' => 'New',
            'last_name' => 'Learner',
            'classroom_id' => $classA->id,
            'academic_year_id' => $yearA->id,
        ])->assertStatus(201)->assertJson(['success' => true]);
    }

    public function test_user_without_school_is_rejected(): void
    {
        $orphan = $this->userWithRole('teacher', null);

        $this->as($orphan)->getJson('/api/v1/students')->assertStatus(403);
    }

    public function test_suspended_school_is_locked_out(): void
    {
        $this->schoolA->update(['status' => 'suspended']);
        $admin = $this->userWithRole('school_admin', $this->schoolA);

        $this->as($admin)->getJson('/api/v1/students')->assertStatus(403);
    }

    public function test_platform_admin_can_open_a_school_with_header(): void
    {
        $owner = $this->userWithRole('super_admin', null);

        $this->as($owner, ['X-School-ID' => (string) $this->schoolB->id])
            ->getJson('/api/v1/school/profile')
            ->assertOk()
            ->assertJsonPath('data.school.id', $this->schoolB->id);

        $this->as($owner, ['X-School-ID' => '999999'])->getJson('/api/v1/students')->assertStatus(404);
    }

    // ── Role checks ──────────────────────────────────────────────

    public function test_teacher_permissions(): void
    {
        $teacher = $this->userWithRole('teacher', $this->schoolA);

        $this->as($teacher)->getJson('/api/v1/academic/classes')->assertOk();
        $this->as($teacher)->getJson('/api/v1/attendance')->assertOk();

        $this->as($teacher)->postJson('/api/v1/academic/classes', ['name' => 'Form 9'])->assertStatus(403);
        $this->as($teacher)->getJson('/api/v1/finance/overview')->assertStatus(403);
        $this->as($teacher)->getJson('/api/v1/school/users')->assertStatus(403);
        $this->as($teacher)->getJson('/api/v1/school/admin/settings')->assertStatus(403);
        $this->as($teacher)->postJson('/api/v1/examinations/assessments/1/publish')->assertStatus(403);
    }

    public function test_accountant_permissions(): void
    {
        $accountant = $this->userWithRole('accountant', $this->schoolA);

        $this->as($accountant)->getJson('/api/v1/finance/categories')->assertOk();
        $this->as($accountant)->postJson('/api/v1/attendance/mark', [])->assertStatus(403);
        $this->as($accountant)->postJson('/api/v1/students', ['first_name' => 'X', 'last_name' => 'Y'])->assertStatus(403);
    }

    public function test_parent_and_student_have_no_staff_portal_access(): void
    {
        foreach (['parent', 'student'] as $role) {
            $user = $this->userWithRole($role, $this->schoolA);
            $this->as($user)->getJson('/api/v1/students')->assertStatus(403);
            $this->as($user)->getJson('/api/v1/school/profile')->assertStatus(403);
        }
    }

    public function test_school_admin_cannot_edit_shared_roles(): void
    {
        $admin = $this->userWithRole('school_admin', $this->schoolA);
        $teacherRole = Role::query()->where('name', 'teacher')->first();

        $this->as($admin)->patchJson("/api/v1/school/roles/{$teacherRole->id}", ['label' => 'Changed'])
            ->assertStatus(403);
        $this->assertSame('Teacher', $teacherRole->fresh()->label);

        $roles = $this->as($admin)->getJson('/api/v1/school/roles')->assertOk()->json('data.roles');
        $this->assertNotContains('super_admin', array_column($roles, 'name'));
    }

    public function test_me_returns_permission_map(): void
    {
        $teacher = $this->userWithRole('teacher', $this->schoolA);

        $this->as($teacher)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.permissions.finance.read', false)
            ->assertJsonPath('data.user.permissions.examinations.write', true)
            ->assertJsonPath('data.user.permissions.academic.read', true)
            ->assertJsonPath('data.user.permissions.academic.write', false);
    }
}
