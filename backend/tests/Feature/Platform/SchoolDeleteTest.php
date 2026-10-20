<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Models\Backup;
use App\Platform\Models\Plan;
use App\Platform\Models\SchoolSubscription;
use App\Platform\Models\Signup;
use App\Platform\Services\SchoolDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Back office → Customers → Delete: a customer and all its data, for good.
 */
class SchoolDeleteTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private FakeSchoolDatabase $database;

    private Tenant $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new FakeSchoolDatabase;
        $this->app->instance(SchoolDatabase::class, $this->database);
        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('backups');
        config(['platform.website_school' => 'newtech', 'platform.backup_disk' => 'backups', 'platform.backup_copy_disk' => null]);

        $this->school = Tenant::factory()->create(['slug' => 'angkor']);
        $user = new User(['name' => 'Owner', 'email' => 'owner@angkor.test']);
        $user->forceFill(['tenant_id' => $this->school->id, 'password' => 'x', 'status' => User::STATUS_ACTIVE])->save();
        SchoolSubscription::query()->create(['tenant_id' => $this->school->id, 'plan_id' => Plan::legacy()->id, 'status' => 'active', 'start_on' => '2026-10-01']);
        Storage::disk('public')->put('tenants/'.$this->school->id.'/logo.png', 'x');
        Storage::disk('backups')->put('angkor/one.dump', 'x');
        Backup::query()->create(['tenant_id' => $this->school->id, 'database' => 'tn_angkor', 'status' => 'done', 'path' => 'angkor/one.dump', 'trigger' => 'manual']);
    }

    public function test_the_owner_deletes_a_customer_and_everything_of_it(): void
    {
        $staff = $this->platformStaff();
        $this->actingAsStaff($staff);
        $id = $this->school->id;

        $this->deleteJson("/api/v1/platform/schools/{$id}", ['confirm' => 'angkor'])->assertOk()->assertJsonPath('data.deleted', true);

        $this->assertSame(['angkor'], $this->database->erased);
        $this->assertNull(Tenant::query()->withTrashed()->find($id));
        $this->assertSame(0, DB::table('users')->where('email', 'owner@angkor.test')->count(), 'its users are deleted, not left without a school');
        $this->assertSame(0, SchoolSubscription::query()->where('tenant_id', $id)->count());
        $this->assertSame(0, Backup::query()->where('tenant_id', $id)->count());
        Storage::disk('backups')->assertMissing('angkor/one.dump');
        Storage::disk('public')->assertMissing('tenants/'.$id.'/logo.png');
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'school.deleted', 'tenant_id' => $id, 'staff_id' => $staff->id]);

        // The code is free again.
        $this->postJson('/api/v1/platform/schools', ['name' => 'Angkor 2', 'slug' => 'angkor', 'owner_name' => 'O', 'owner_email' => 'o@x.test'])->assertCreated();
    }

    public function test_backups_can_be_kept(): void
    {
        $this->actingAsStaff($this->platformStaff());

        $this->deleteJson("/api/v1/platform/schools/{$this->school->id}", ['confirm' => 'angkor', 'keep_backups' => true])->assertOk();

        $this->assertSame(1, Backup::query()->count());
        $this->assertNull(Backup::query()->value('tenant_id'));
        Storage::disk('backups')->assertExists('angkor/one.dump');
    }

    public function test_the_code_must_be_typed_exactly(): void
    {
        $this->actingAsStaff($this->platformStaff());

        $this->deleteJson("/api/v1/platform/schools/{$this->school->id}", ['confirm' => 'Angkor'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->assertNotNull($this->school->fresh());
        $this->assertSame([], $this->database->erased);
    }

    public function test_the_website_school_cannot_be_deleted(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $newtech = Tenant::factory()->create(['slug' => 'newtech']);

        $this->deleteJson("/api/v1/platform/schools/{$newtech->id}", ['confirm' => 'newtech'])->assertUnprocessable();
        $this->assertNotNull($newtech->fresh());
    }

    public function test_nothing_central_is_deleted_when_its_data_cannot_be_reached(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $this->database->failErase = true;

        $this->deleteJson("/api/v1/platform/schools/{$this->school->id}", ['confirm' => 'angkor'])->assertServerError();
        $this->assertNotNull($this->school->fresh());
        $this->assertSame(1, DB::table('users')->where('email', 'owner@angkor.test')->count());
    }

    public function test_staff_without_the_delete_permission_get_a_404(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.school.read', 'platform.school.write', 'platform.school.status']));

        $this->deleteJson("/api/v1/platform/schools/{$this->school->id}", ['confirm' => 'angkor'])->assertNotFound();
        $this->assertNotNull($this->school->fresh());
    }

    public function test_a_sign_up_keeps_its_row_without_the_school(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $signup = Signup::query()->create([
            'token' => (string) \Illuminate\Support\Str::uuid(), 'kind' => 'trial', 'school_name' => 'Angkor', 'slug' => 'angkor',
            'owner_name' => 'O', 'owner_email' => 'o@angkor.test', 'owner_phone' => '012345678', 'status' => 'created', 'tenant_id' => $this->school->id,
        ]);

        $this->deleteJson("/api/v1/platform/schools/{$this->school->id}", ['confirm' => 'angkor'])->assertOk();
        $this->assertNull($signup->fresh()->tenant_id);
    }
}
