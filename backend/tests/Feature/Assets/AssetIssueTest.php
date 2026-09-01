<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Support\Assets\AssetStatus;
use App\Support\Assets\IssuePriority;
use App\Support\Assets\IssueStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAssetCatalog;
use Tests\TestCase;

class AssetIssueTest extends TestCase
{
    use HasAcademicAdmin, HasAssetCatalog, RefreshDatabase;

    public function test_reporting_an_issue_on_an_in_use_asset_moves_its_status(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSET_ISSUES_CREATE]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset(['status' => AssetStatus::IN_USE]);

        $response = $this->postJson("/api/v1/assets/{$asset->id}/issues", [
            'title' => 'Screen flickers',
            'priority' => IssuePriority::HIGH,
            'description' => 'The monitor flickers intermittently.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', IssueStatus::OPEN);
        $this->assertSame(AssetStatus::ISSUE_REPORTED, $asset->fresh()->status);
    }

    public function test_reporting_an_issue_on_an_in_stock_asset_does_not_force_an_illegal_status_move(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSET_ISSUES_CREATE]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset(['status' => AssetStatus::IN_STOCK]);

        $this->postJson("/api/v1/assets/{$asset->id}/issues", ['title' => 'Found a defect during storage'])->assertCreated();

        $this->assertSame(AssetStatus::IN_STOCK, $asset->fresh()->status);
    }

    public function test_resolving_an_issue_marks_it_resolved(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSET_ISSUES_CREATE, Permissions::ASSET_ISSUES_RESOLVE]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset(['status' => AssetStatus::IN_USE]);

        $issueId = $this->postJson("/api/v1/assets/{$asset->id}/issues", ['title' => 'Minor issue'])->assertCreated()->json('data.id');

        $response = $this->postJson("/api/v1/asset-issues/{$issueId}/resolve", ['notes' => 'Fixed by restart']);
        $response->assertOk();
        $response->assertJsonPath('data.status', IssueStatus::RESOLVED);
    }

    public function test_an_already_resolved_issue_cannot_be_resolved_again(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSET_ISSUES_CREATE, Permissions::ASSET_ISSUES_RESOLVE]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset(['status' => AssetStatus::IN_USE]);

        $issueId = $this->postJson("/api/v1/assets/{$asset->id}/issues", ['title' => 'Minor issue'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/asset-issues/{$issueId}/resolve", [])->assertOk();

        $this->postJson("/api/v1/asset-issues/{$issueId}/resolve", [])->assertUnprocessable();
    }

    public function test_reporting_an_issue_requires_the_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset();

        $this->postJson("/api/v1/assets/{$asset->id}/issues", ['title' => 'X'])->assertForbidden();
    }
}
