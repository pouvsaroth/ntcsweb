<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Position;
use App\Models\Role;
use App\Models\Staff;
use App\Services\Academic\StaffIdGenerator;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class StaffIdGeneratorTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_the_first_staff_member_gets_number_one(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE]);

        $response = $this->createStaff();

        $response->assertJsonPath('data.employee_code', 'NTSS-0001');
    }

    public function test_the_second_staff_member_gets_number_two(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE]);

        $this->createStaff();
        $response = $this->createStaff();

        $response->assertJsonPath('data.employee_code', 'NTSS-0002');
    }

    #[DataProvider('paddingExamples')]
    public function test_the_sequence_is_zero_padded_to_four_digits(int $createCount, string $expectedLastCode): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE]);

        // Seed the counter directly rather than looping 999 real HTTP
        // requests — this test is about the sprintf('%04d', ...) formatting,
        // not about re-proving sequencing itself (covered above).
        DB::connection('tenant')->table('staff_id_sequences')->insert([
            'prefix' => 'NTSS',
            'next_number' => $createCount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->createStaff();

        $response->assertJsonPath('data.employee_code', $expectedLastCode);
    }

    public static function paddingExamples(): array
    {
        return [
            'single digit' => [1, 'NTSS-0001'],
            'two digits' => [25, 'NTSS-0025'],
            'three digits' => [999, 'NTSS-0999'],
            'four digits, no truncation' => [1000, 'NTSS-1000'],
        ];
    }

    public function test_changing_the_prefix_starts_a_new_sequence(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE, Permissions::TENANT_SETTINGS_UPDATE]);

        $ntssResponse = $this->createStaff();
        $ntssResponse->assertJsonPath('data.employee_code', 'NTSS-0001');

        $this->postJson('/api/v1/settings/general', ['staff_id_prefix' => 'ABC'])->assertOk();

        $abcResponse = $this->createStaff();
        $abcResponse->assertJsonPath('data.employee_code', 'ABC-0001');

        // The earlier NTSS staff member is untouched by the prefix change.
        $this->assertDatabaseHas('staff', ['employee_code' => 'NTSS-0001'], connection: 'tenant');
    }

    public function test_switching_back_to_a_previous_prefix_resumes_its_own_count(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE, Permissions::TENANT_SETTINGS_UPDATE]);

        $this->createStaff()->assertJsonPath('data.employee_code', 'NTSS-0001');

        $this->postJson('/api/v1/settings/general', ['staff_id_prefix' => 'ABC'])->assertOk();
        $this->createStaff()->assertJsonPath('data.employee_code', 'ABC-0001');

        $this->postJson('/api/v1/settings/general', ['staff_id_prefix' => 'NTSS'])->assertOk();
        $response = $this->createStaff();

        // NOT NTSS-0001 — the NTSS counter kept its own place the whole time.
        $response->assertJsonPath('data.employee_code', 'NTSS-0002');
    }

    public function test_the_database_rejects_a_duplicate_employee_code_within_a_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW]);
        Staff::factory()->create(['employee_code' => 'NTSS-0001']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Staff::factory()->create(['employee_code' => 'NTSS-0001']);
    }

    /**
     * PHPUnit can't fork real concurrent HTTP requests, so this proves the
     * generator itself never repeats a number across many sequential calls
     * within one process; the true concurrency guarantee is the
     * `SELECT ... FOR UPDATE` row lock in StaffIdGenerator::next() plus the
     * DB-level unique constraint proven above as the last line of defense if
     * that lock were ever bypassed.
     */
    public function test_many_sequential_generations_never_repeat_a_code(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE]);
        $tenant = $this->tenant;
        $generator = app(StaffIdGenerator::class);

        $codes = collect(range(1, 25))->map(fn () => $generator->next($tenant->fresh()));

        $this->assertCount(25, $codes->unique());
    }

    public function test_a_client_supplied_employee_code_is_ignored_in_favor_of_the_generated_one(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE]);
        $role = Role::factory()->forTenant($this->tenant)->create();
        $position = Position::factory()->create(['role_id' => $role->id]);

        $response = $this->postJson('/api/v1/staff', [
            'employee_code' => 'NTSS-9999',
            'first_name' => 'Dara',
            'last_name' => 'Sok',
            'phone' => '012345678',
            'position_id' => $position->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.employee_code', 'NTSS-0001');
    }

    private function createStaff(): \Illuminate\Testing\TestResponse
    {
        static $counter = 0;
        $counter++;

        $role = Role::factory()->forTenant($this->tenant)->create();
        $position = Position::factory()->create(['role_id' => $role->id]);

        return $this->postJson('/api/v1/staff', [
            'first_name' => 'Staff',
            'last_name' => (string) $counter,
            'phone' => '02000'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
            'position_id' => $position->id,
        ]);
    }
}
