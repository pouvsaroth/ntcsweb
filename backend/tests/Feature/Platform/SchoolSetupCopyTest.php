<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\AcademicProgram;
use App\Models\Account;
use App\Models\Classroom;
use App\Models\CoursePackage;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Product;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Models\SchoolProvisioningLog;
use App\Platform\Services\SchoolDatabase;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/** Back office → Create school → "Copy setup from": setup only, never people. */
class SchoolSetupCopyTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SchoolDatabase::class, new FakeSchoolDatabase);
        Notification::fake();
        $this->actingAsStaff($this->platformStaff());
    }

    private function createSchool(string $slug, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/platform/schools', array_merge([
            'name' => 'School '.$slug, 'slug' => $slug,
            'owner_name' => 'Owner '.$slug, 'owner_email' => $slug.'@example.com',
        ], $extra));
    }

    private function in(Tenant $tenant, callable $work): mixed
    {
        return app(TenantContext::class)->runFor($tenant, $work);
    }

    public function test_a_new_school_gets_the_setup_with_new_ids_and_no_people(): void
    {
        $source = Tenant::query()->findOrFail($this->createSchool('angkor')->assertCreated()->json('data.id'));

        $customRole = Role::factory()->create(['tenant_id' => $source->id, 'slug' => 'front-desk', 'name' => 'Front desk']);
        $customRole->permissions()->attach(Permission::query()->limit(3)->pluck('id'));

        $ids = $this->in($source, function () use ($customRole) {
            $parent = Account::factory()->create(['code' => '9100', 'name' => 'Parent']);
            $child = Account::factory()->create(['code' => '9110', 'name' => 'Child', 'parent_id' => $parent->id]);
            $program = AcademicProgram::factory()->create(['code' => 'COMP', 'name' => 'Computer']);
            $product = Product::factory()->create(['code' => 'P-WORD', 'revenue_account_id' => $child->id]);
            $package = CoursePackage::factory()->create(['code' => 'WORD', 'academic_program_id' => $program->id, 'product_id' => $product->id, 'fee_term' => 45]);
            $room = Classroom::factory()->create(['name' => 'Room 101']);
            $teacher = Staff::factory()->create();
            SchoolClass::factory()->create(['name' => 'Word evening', 'classroom_id' => $room->id, 'academic_program_id' => $program->id, 'homeroom_staff_id' => $teacher->id]);
            Position::factory()->create(['name' => 'Receptionist', 'role_id' => $customRole->id]);
            Student::factory()->count(2)->create();

            return ['child' => $child->id, 'package' => $package->id, 'accounts' => Account::query()->count()];
        });
        $source->update(['settings' => [...($source->settings ?? []), 'term_study_alert_days' => 45, 'accounting' => ['default_revenue_account_id' => $ids['child']]]]);

        $targetId = $this->createSchool('bayon', ['copy_setup_from' => $source->id])->assertCreated()->json('data.id');
        $target = Tenant::query()->findOrFail($targetId);

        $this->assertSame(Tenant::STATUS_ACTIVE, $target->status, (string) \App\Platform\Models\School::query()->find($targetId)?->status_detail);
        $this->assertSame('ok', SchoolProvisioningLog::query()->where('tenant_id', $targetId)->where('step', 'copy')->value('status'));

        $this->in($target, function () use ($ids, $target) {
            // One chart of accounts (the seeded one replaced), links pointing at the new rows.
            $this->assertSame($ids['accounts'], Account::query()->count());
            $child = Account::query()->where('code', '9110')->firstOrFail();
            $this->assertSame(Account::query()->where('code', '9100')->value('id'), $child->parent_id);
            $this->assertNotSame($ids['child'], $child->id);

            $package = CoursePackage::query()->where('code', 'WORD')->firstOrFail();
            $this->assertNotSame($ids['package'], $package->id);
            $this->assertSame(AcademicProgram::query()->where('code', 'COMP')->value('id'), $package->academic_program_id);
            $this->assertEquals(45, $package->fee_term);
            $this->assertSame($child->id, Product::query()->whereKey($package->product_id)->value('revenue_account_id'));

            $class = SchoolClass::query()->where('name', 'Word evening')->firstOrFail();
            $this->assertSame(Classroom::query()->where('name', 'Room 101')->value('id'), $class->classroom_id);
            $this->assertNull($class->homeroom_staff_id);

            $position = Position::query()->where('name', 'Receptionist')->firstOrFail();
            $role = Role::query()->withoutGlobalScopes()->findOrFail($position->role_id);
            $this->assertSame([$target->id, 'front-desk'], [$role->tenant_id, $role->slug]);
            $this->assertCount(3, $role->permissions);

            // People are never copied.
            $this->assertSame(0, Student::query()->count());
            $this->assertSame(0, Staff::query()->count());
            $this->assertSame(['bayon@example.com'], User::query()->withoutGlobalScopes()->where('tenant_id', $target->id)->pluck('email')->all());

            $this->assertSame($child->id, $target->fresh()->setting('accounting.default_revenue_account_id'));
            $this->assertSame(45, $target->fresh()->setting('term_study_alert_days'));
        });

        // The source school is untouched.
        $this->in($source, function () use ($ids) {
            $this->assertSame($ids['accounts'], Account::query()->count());
            $this->assertSame(2, Student::query()->count());
            $this->assertNotNull(SchoolClass::query()->where('name', 'Word evening')->value('homeroom_staff_id'));
        });
    }

    public function test_leftover_links_to_deleted_setup_rows_are_skipped_not_fatal(): void
    {
        $source = Tenant::query()->findOrFail($this->createSchool('angkor')->assertCreated()->json('data.id'));
        $this->in($source, function () {
            $room = Classroom::factory()->create(['name' => 'Room 101']);
            \App\Models\ClassroomTable::factory()->create(['classroom_id' => $room->id, 'name' => 'Table 1']);
            // A room deleted long ago, its table left behind (no foreign key on this link).
            \App\Models\ClassroomTable::factory()->create(['classroom_id' => 987654, 'name' => 'Leftover']);
        });

        $targetId = $this->createSchool('bayon', ['copy_setup_from' => $source->id])->assertCreated()->json('data.id');
        $target = Tenant::query()->findOrFail($targetId);

        $this->assertSame(Tenant::STATUS_ACTIVE, $target->status, (string) \App\Platform\Models\School::query()->find($targetId)?->status_detail);
        $this->in($target, function () {
            $tables = \App\Models\ClassroomTable::query()->get();
            $this->assertSame(['Table 1'], $tables->pluck('name')->all());
            $this->assertSame(Classroom::query()->where('name', 'Room 101')->value('id'), $tables[0]->classroom_id);
        });
    }

    public function test_the_school_to_copy_from_must_exist(): void
    {
        $this->createSchool('bayon', ['copy_setup_from' => 999999])->assertUnprocessable()->assertJsonValidationErrors('copy_setup_from');
    }
}
