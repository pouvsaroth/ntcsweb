<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\Models\QuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

class QuotationItemsTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    public function test_the_catalog_comes_filled_from_the_system_sheet_as_a_tree(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.billing.read']));

        $tree = collect($this->getJson('/api/v1/platform/billing/quotation-items')->assertOk()->json('data'));

        $academic = $tree->firstWhere('code', 'B4');
        $this->assertSame('Academic', $academic['name']);
        $this->assertSame('ការសិក្សា', $academic['name_km']);
        $programs = collect($academic['children'])->firstWhere('code', 'B4.1');
        $this->assertSame('submenu', $programs['level']);
        $this->assertSame('/academic-programs', $programs['path']);
        $this->assertSame('Academic Programs', $programs['children'][0]['name']);
        $this->assertSame('tab', $programs['children'][0]['level']);
        $this->assertSame(16, $tree->count());
    }

    public function test_prices_are_set_and_items_added_under_the_right_level(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.billing.read', 'platform.billing.manage']));
        $submenu = QuotationItem::query()->where('code', 'B4.1')->sole();

        $this->patchJson("/api/v1/platform/billing/quotation-items/{$submenu->id}", ['price_usd' => 120.5, 'price_khr' => 490000, 'active' => false])
            ->assertOk()->assertJsonPath('data.price_usd', 120.5)->assertJsonPath('data.active', false);

        $this->postJson('/api/v1/platform/billing/quotation-items', ['parent_id' => $submenu->id, 'name' => 'Book Loans', 'price_usd' => 10])
            ->assertCreated()->assertJsonPath('data.level', 'tab');

        $tab = QuotationItem::query()->where('parent_id', $submenu->id)->orderBy('id')->first();
        $this->postJson('/api/v1/platform/billing/quotation-items', ['parent_id' => $tab->id, 'name' => 'Too deep'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'billing.quotation_item_updated']);
    }

    public function test_deleting_a_menu_removes_everything_under_it(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.billing.read', 'platform.billing.manage']));
        $menu = QuotationItem::query()->where('code', 'B16')->sole();

        $this->deleteJson("/api/v1/platform/billing/quotation-items/{$menu->id}")->assertNoContent();

        $this->assertSame(0, QuotationItem::query()->where('code', 'like', 'B16%')->count());
    }

    public function test_viewing_alone_cannot_change_the_catalog(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.billing.read']));
        $item = QuotationItem::query()->where('code', 'B1')->sole();

        $this->patchJson("/api/v1/platform/billing/quotation-items/{$item->id}", ['price_usd' => 1])->assertNotFound();
    }
}
