<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Billing\Modules;
use App\Platform\Jobs\ProvisionSchool;
use App\Platform\Models\Backup;
use App\Platform\Models\Plan;
use App\Platform\Models\SchoolSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 6a (docs/backoffice/stage-6-support.md): one school's health, and
 * the Schools list dot from central tables only.
 */
class SchoolHealthTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-18 05:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function central(): \Illuminate\Database\Connection
    {
        return DB::connection(config('tenancy.database.central_connection'));
    }

    /** A school with a live yearly plan, a fresh backup and a sign-in today. */
    private function healthySchool(): Tenant
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::query()->create([
            'code' => 'p-'.fake()->unique()->numberBetween(1, 99999), 'name' => 'Basic', 'price' => 50, 'currency' => 'USD',
            'period_months' => 12, 'modules' => Modules::codes(), 'status' => Plan::STATUS_ACTIVE,
        ]);
        SchoolSubscription::query()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'start_on' => '2026-10-01', 'end_on' => '2027-09-30']);
        $this->backup($tenant, now()->subHours(3));
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Dara Teacher']);
        // Written in the school's own time, as a console sign-in does.
        $user->forceFill(['last_login_at' => now('Asia/Phnom_Penh')->subHour()->format('Y-m-d H:i:s')])->save();

        return $tenant;
    }

    private function backup(Tenant $tenant, Carbon $at, array $extra = []): Backup
    {
        return Backup::query()->forceCreate($extra + [
            'database' => 'db_'.$tenant->id, 'tenant_id' => $tenant->id, 'format' => Backup::FORMAT_SCHOOL,
            'trigger' => 'scheduled', 'status' => Backup::STATUS_OK, 'path' => 'x.zip',
            'started_at' => $at, 'finished_at' => $at, 'copied_at' => $at,
        ]);
    }

    private function failedJob(?int $tenantId, Carbon $at, string $message = 'RuntimeException: SMTP refused'): void
    {
        $this->central()->table('failed_jobs')->insert([
            'uuid' => (string) fake()->uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'X', 'ntcs_tenant_id' => $tenantId]),
            'exception' => $message."\n#0 /var/www/app/Secret.php(12): boom",
            'failed_at' => $at,
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function lines(Tenant $tenant): array
    {
        $this->actingAsStaff($this->platformStaff(['platform.support.read']));
        $lines = $this->getJson("/api/v1/platform/schools/{$tenant->id}/health")->assertOk()->json('data.lines');

        return collect($lines)->keyBy('key')->all();
    }

    public function test_a_healthy_school_reports_every_line_from_its_source(): void
    {
        $tenant = $this->healthySchool();

        $lines = $this->lines($tenant);

        $this->assertSame(['status', 'database', 'backup', 'plan', 'students', 'users', 'sign_in', 'failed_jobs'], array_keys($lines));
        $this->assertSame('ok', $lines['status']['state']);
        $this->assertSame('ok', $lines['backup']['state']);
        $this->assertSame('ok', $lines['plan']['state']);
        $this->assertSame('ok', $lines['sign_in']['state']);
        $this->assertSame('Dara Teacher', $lines['sign_in']['name']);
        $this->assertSame('2026-10-18T04:00:00+00:00', $lines['sign_in']['at']);
        $this->assertSame(1, $lines['users']['total']);
        $this->assertSame(1, $lines['users']['active']);
        $this->assertSame(0, $lines['failed_jobs']['count']);
    }

    public function test_problems_are_flagged_at_their_thresholds(): void
    {
        $tenant = $this->healthySchool();
        Backup::query()->where('tenant_id', $tenant->id)->update(['finished_at' => now()->subDays(9)]); // daily schedule → stale
        User::query()->where('tenant_id', $tenant->id)->update(['last_login_at' => now()->subDays(15)]);
        SchoolSubscription::query()->where('tenant_id', $tenant->id)->update(['end_on' => '2026-10-25']); // within the warning days
        $this->failedJob($tenant->id, now()->subDays(2));
        $this->failedJob($tenant->id, now()->subDays(8)); // older than 7 days: not counted

        $lines = $this->lines($tenant);

        $this->assertSame('bad', $lines['backup']['state']);
        $this->assertSame('warn', $lines['sign_in']['state']);
        $this->assertSame('warn', $lines['plan']['state']);
        $this->assertSame('expiring', $lines['plan']['flag']);
        $this->assertSame('warn', $lines['failed_jobs']['state']);
        $this->assertSame(1, $lines['failed_jobs']['count']);
        // The message line only — never the stack trace.
        $this->assertSame('RuntimeException: SMTP refused', $lines['failed_jobs']['latest']['message']);
    }

    public function test_a_school_with_no_backup_and_a_failed_copy(): void
    {
        $none = Tenant::factory()->create();
        $this->assertSame('bad', $this->lines($none)['backup']['state']);

        $copyFailed = $this->healthySchool();
        Backup::query()->where('tenant_id', $copyFailed->id)->update(['copied_at' => null, 'copy_error' => 'Disk full']);
        $this->assertSame('warn', $this->lines($copyFailed)['backup']['state']);
    }

    public function test_another_schools_failed_jobs_are_not_counted(): void
    {
        $tenant = $this->healthySchool();
        $other = Tenant::factory()->create();
        $this->failedJob($other->id, now()->subDay());
        $this->failedJob(null, now()->subDay());

        $this->assertSame(0, $this->lines($tenant)['failed_jobs']['count']);
    }

    public function test_a_suspended_school_is_bad_and_a_school_being_set_up_skips_its_data_lines(): void
    {
        $suspended = $this->healthySchool();
        $suspended->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();
        $this->assertSame('bad', $this->lines($suspended)['status']['state']);

        $new = Tenant::factory()->create();
        $new->forceFill(['status' => Tenant::STATUS_PROVISIONING])->save();
        $lines = $this->lines($new);
        $this->assertSame('warn', $lines['status']['state']);
        foreach (['database', 'backup', 'students', 'sign_in'] as $key) {
            $this->assertSame('off', $lines[$key]['state'], $key);
        }
    }

    public function test_the_schools_list_shows_a_dot_for_staff_who_may_see_health(): void
    {
        $healthy = $this->healthySchool();
        $suspended = $this->healthySchool();
        $suspended->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();
        $noBackup = $this->healthySchool();
        Backup::query()->where('tenant_id', $noBackup->id)->delete();

        $this->actingAsStaff($this->platformStaff(['platform.school.read', 'platform.support.read']));
        $rows = collect($this->getJson('/api/v1/platform/schools?per_page=100')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('ok', $rows[$healthy->id]['health']['state']);
        $this->assertSame('bad', $rows[$suspended->id]['health']['state']);
        $this->assertSame(['status'], $rows[$suspended->id]['health']['reasons']);
        $this->assertSame('bad', $rows[$noBackup->id]['health']['state']);
        $this->assertSame(['backup'], $rows[$noBackup->id]['health']['reasons']);

        $this->actingAsStaff($this->platformStaff(['platform.school.read']));
        $row = collect($this->getJson('/api/v1/platform/schools?per_page=100')->assertOk()->json('data'))->firstWhere('id', $healthy->id);
        $this->assertNull($row['health']);
    }

    public function test_health_needs_the_support_read_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsStaff($this->platformStaff(['platform.school.read']));

        // 404, not 403: a refusal does not confirm the route exists (EnsureStaffPermission).
        $this->getJson("/api/v1/platform/schools/{$tenant->id}/health")->assertNotFound();
    }

    public function test_queued_jobs_are_tagged_with_their_school(): void
    {
        config(['queue.default' => 'database']);
        $tenant = Tenant::factory()->create();

        ProvisionSchool::dispatch($tenant->id);

        $payload = json_decode((string) $this->central()->table('jobs')->latest('id')->value('payload'), true);
        $this->assertSame($tenant->id, $payload['ntcs_tenant_id']);
    }
}
