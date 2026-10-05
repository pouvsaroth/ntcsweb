<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Support\Audit\AuditAction;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class AuditLogClearTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function insertLog(int $tenantId, string $createdAt): void
    {
        DB::table('audit_logs')->insert([
            'tenant_id' => $tenantId,
            'event' => 'students.update',
            'action' => AuditAction::UPDATE,
            'module' => 'Students',
            'created_at' => $createdAt,
        ]);
    }

    public function test_clearing_deletes_only_logs_inside_the_range_and_records_the_clear(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::AUDIT_LOGS_DELETE]);
        AuditLog::query()->delete();

        $this->insertLog($this->tenant->id, '2026-01-31 23:59:00');
        $this->insertLog($this->tenant->id, '2026-02-01 00:00:00');
        $this->insertLog($this->tenant->id, '2026-02-15 12:00:00');
        $this->insertLog($this->tenant->id, '2026-02-28 23:59:00');
        $this->insertLog($this->tenant->id, '2026-03-01 00:00:00');

        $this->postJson('/api/v1/audit-logs/clear', ['date_from' => '2026-02-01', 'date_to' => '2026-02-28'])
            ->assertOk()
            ->assertJsonPath('data.deleted', 3);

        $remaining = AuditLog::query()->where('action', '!=', AuditAction::AUDIT_LOGS_CLEARED)->pluck('created_at')->map->toDateString()->sort()->values()->all();
        $this->assertSame(['2026-01-31', '2026-03-01'], $remaining);

        $record = AuditLog::query()->where('action', AuditAction::AUDIT_LOGS_CLEARED)->sole();
        $this->assertSame($this->admin->id, $record->user_id);
        $this->assertSame(3, $record->new_values['deleted']);
    }

    public function test_clearing_never_touches_another_schools_logs(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::AUDIT_LOGS_DELETE]);
        $other = $this->createForOtherTenant(fn () => Tenant::factory()->create());
        $this->insertLog($other->id, '2026-02-10 10:00:00');

        $this->postJson('/api/v1/audit-logs/clear', ['date_from' => '2026-02-01', 'date_to' => '2026-02-28'])->assertOk();

        $this->assertSame(1, DB::table('audit_logs')->where('tenant_id', $other->id)->count());
    }

    public function test_a_user_who_can_only_view_cannot_clear(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::AUDIT_LOGS_VIEW]);
        $this->insertLog($this->tenant->id, '2026-02-10 10:00:00');

        $this->postJson('/api/v1/audit-logs/clear', ['date_from' => '2026-02-01', 'date_to' => '2026-02-28'])->assertForbidden();

        $this->assertSame(1, DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->whereDate('created_at', '2026-02-10')->count());
    }

    public function test_the_end_date_cannot_be_before_the_start_date(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::AUDIT_LOGS_DELETE]);

        $this->postJson('/api/v1/audit-logs/clear', ['date_from' => '2026-03-01', 'date_to' => '2026-02-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }
}
