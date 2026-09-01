<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\AssetHistory;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Staff;
use App\Support\Accounting\ExpenseStatus;
use App\Support\Assets\AssetStatus;
use App\Support\Audit\AuditAction;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAssetCatalog;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/**
 * The spec's own section 60 walkthrough, reproduced end to end: purchase ->
 * assign -> issue -> repair -> complete -> return -> reassign. Confirms
 * Asset History carries the full narrative, Audit Log records who did each
 * step, and the repair's cost reaches Accounting through the normal
 * approval-gated Expense workflow (never auto-posted).
 */
class AssetEndToEndScenarioTest extends TestCase
{
    use HasAcademicAdmin, HasAssetCatalog, HasChartOfAccounts, RefreshDatabase;

    public function test_the_full_asset_lifecycle_scenario(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::ASSETS_CREATE, Permissions::ASSETS_ASSIGN, Permissions::ASSETS_RETURN,
            Permissions::ASSET_ISSUES_CREATE, Permissions::ASSET_REPAIRS_CREATE, Permissions::ASSET_REPAIRS_COMPLETE,
        ]);
        $this->setUpAssetCatalog();
        $this->setUpChartOfAccounts();

        $staffA = Staff::factory()->forTenant($this->tenant)->create();
        $staffB = Staff::factory()->forTenant($this->tenant)->create();

        // 1. Purchase.
        $assetId = $this->postJson('/api/v1/assets', [
            'category_id' => $this->computerCategory->id,
            'name' => 'Dell Optiplex 3080',
            'brand' => 'Dell',
            'model' => 'Optiplex 3080',
            'purchase_price' => 650,
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->mainLocation->id,
        ])->assertCreated()->json('data.id');

        // 2. Assign to Staff A.
        $this->postJson("/api/v1/assets/{$assetId}/assign", [
            'assignable_type' => 'staff',
            'assignable_id' => $staffA->id,
        ])->assertCreated();
        $this->assertSame(AssetStatus::ASSIGNED, \App\Models\Asset::find($assetId)->status);

        // 3. Report an issue.
        $issueId = $this->postJson("/api/v1/assets/{$assetId}/issues", [
            'title' => 'Will not power on',
            'priority' => 'HIGH',
        ])->assertCreated()->json('data.id');
        $this->assertSame(AssetStatus::ISSUE_REPORTED, \App\Models\Asset::find($assetId)->status);

        // 4. Send to repair, referencing the issue.
        $repairId = $this->postJson("/api/v1/assets/{$assetId}/repairs", [
            'issue_id' => $issueId,
            'repair_shop_id' => $this->repairShop->id,
            'problem_description' => 'Power supply suspected faulty.',
        ])->assertCreated()->json('data.id');
        $this->assertSame(AssetStatus::UNDER_REPAIR, \App\Models\Asset::find($assetId)->status);

        // 5. Complete the repair with diagnosis + cost breakdown.
        $this->postJson("/api/v1/asset-repairs/{$repairId}/complete", [
            'expense_account_id' => $this->electricityAccount->id,
            'diagnosis_cost' => 10,
            'parts_cost' => 45,
            'labor_cost' => 20,
            'repair_description' => 'Replaced power supply unit.',
            'condition_after_repair' => 'GOOD',
        ])->assertOk()->assertJsonPath('data.total_cost', 75);
        $this->assertSame(AssetStatus::REPAIR_COMPLETED, \App\Models\Asset::find($assetId)->status);

        // Accounting recognizes the repair expense, pending approval — never auto-posted.
        $expense = Expense::query()->where('reference_type', \App\Models\AssetRepair::class)->where('reference_id', $repairId)->firstOrFail();
        $this->assertSame(ExpenseStatus::PENDING_APPROVAL, $expense->status);
        $this->assertSame('75.00', (string) $expense->amount);

        // 6. Return to stock, then reassign to Staff B.
        $this->postJson("/api/v1/assets/{$assetId}/return", ['condition_at_return' => 'GOOD'])->assertOk();
        $this->assertSame(AssetStatus::IN_STOCK, \App\Models\Asset::find($assetId)->status);

        $this->postJson("/api/v1/assets/{$assetId}/assign", [
            'assignable_type' => 'staff',
            'assignable_id' => $staffB->id,
        ])->assertCreated();
        $this->assertSame(AssetStatus::ASSIGNED, \App\Models\Asset::find($assetId)->status);

        // Asset History carries every step of the narrative.
        $events = AssetHistory::where('asset_id', $assetId)->orderBy('id')->pluck('event_type')->all();
        $this->assertContains('CREATED', $events);
        $this->assertContains('ASSIGNED', $events);
        $this->assertContains('ISSUE_REPORTED', $events);
        $this->assertContains('SENT_TO_REPAIR', $events);
        $this->assertContains('REPAIR_COMPLETED', $events);
        $this->assertContains('RETURNED', $events);
        $this->assertSame(2, collect($events)->filter(fn ($e) => $e === 'ASSIGNED')->count());

        // Audit Log records who performed each step.
        $this->assertTrue(AuditLog::where('action', AuditAction::ASSET_CREATED)->exists());
        $this->assertTrue(AuditLog::where('action', AuditAction::ASSET_ASSIGNED)->exists());
        $this->assertTrue(AuditLog::where('action', AuditAction::ASSET_SENT_TO_REPAIR)->exists());
        $this->assertTrue(AuditLog::where('action', AuditAction::ASSET_REPAIR_COMPLETED)->exists());
        $this->assertTrue(AuditLog::where('action', AuditAction::ASSET_RETURNED)->exists());
    }
}
