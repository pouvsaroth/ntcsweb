<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Platform\Billing\QuotationExport;
use App\Platform\Models\Quotation;
use App\Platform\Models\QuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

class QuotationsTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private function body(array $over = []): array
    {
        $tab = QuotationItem::query()->where('code', 'B4.1.1')->sole();
        $submenu = QuotationItem::query()->where('code', 'B4.6')->sole();

        return array_merge([
            'customer_name' => 'Sunrise School',
            'phone' => '012 345 678',
            'currency' => 'USD',
            'issue_date' => '2026-11-11',
            'valid_until' => '2026-12-11',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'tax_percent' => 10,
            'status' => 'draft',
            'lines' => [
                ['item_id' => $tab->id, 'quantity' => 1, 'unit_price' => 100],
                ['item_id' => $submenu->id, 'quantity' => 2, 'unit_price' => 50.555, 'description' => 'Class and teacher timetables'],
                ['name' => 'Setup and training', 'quantity' => 1, 'unit_price' => 80],
            ],
        ], $over);
    }

    public function test_a_quotation_is_numbered_its_lines_named_and_totals_worked_out(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.billing.read', 'platform.billing.manage']));

        $res = $this->postJson('/api/v1/platform/billing/quotations', $this->body())->assertCreated();

        $res->assertJsonPath('data.number', 'Q-2026-0001');
        // 100 + 2 × 50.56 + 80 = 281.12; less 10% = 253.01; plus 10% tax = 278.31
        $res->assertJsonPath('data.subtotal', 281.12);
        $res->assertJsonPath('data.discount_amount', 28.11);
        $res->assertJsonPath('data.tax_amount', 25.3);
        $res->assertJsonPath('data.total', 278.31);
        $res->assertJsonPath('data.lines.0.name', 'Academic Programs');
        $res->assertJsonPath('data.lines.0.parent_label', 'Academic › Programs');
        $res->assertJsonPath('data.lines.0.level', 'tab');
        $res->assertJsonPath('data.lines.1.parent_label', 'Academic');
        $res->assertJsonPath('data.lines.2.name', 'Setup and training');
        $res->assertJsonPath('data.lines.2.item_id', null);

        $this->postJson('/api/v1/platform/billing/quotations', $this->body())->assertCreated()->assertJsonPath('data.number', 'Q-2026-0002');
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'billing.quotation_created']);
    }

    public function test_khr_rounds_to_whole_riel_and_a_fixed_discount_never_goes_below_zero(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.billing.read', 'platform.billing.manage']));

        $this->postJson('/api/v1/platform/billing/quotations', $this->body([
            'currency' => 'KHR', 'discount_type' => 'amount', 'discount_value' => 999999999, 'tax_percent' => 0,
            'lines' => [['name' => 'Package', 'quantity' => 1, 'unit_price' => 410000.6]],
        ]))->assertCreated()
            ->assertJsonPath('data.subtotal', 410001)
            ->assertJsonPath('data.discount_amount', 410001)
            ->assertJsonPath('data.total', 0);
    }

    public function test_changing_the_catalog_never_changes_a_saved_quotation(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.billing.read', 'platform.billing.manage']));
        $id = $this->postJson('/api/v1/platform/billing/quotations', $this->body())->json('data.id');

        $tab = QuotationItem::query()->where('code', 'B4.1.1')->sole();
        $tab->update(['name' => 'Renamed', 'price_usd' => 999]);
        $tab->delete();

        $this->getJson("/api/v1/platform/billing/quotations/{$id}")->assertOk()
            ->assertJsonPath('data.lines.0.name', 'Academic Programs')
            ->assertJsonPath('data.lines.0.unit_price', 100)
            ->assertJsonPath('data.lines.0.item_id', null);
    }

    public function test_update_replaces_lines_keeps_the_number_and_list_filters(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.billing.read', 'platform.billing.manage']));
        $tenant = Tenant::factory()->create(['name' => 'Alpha School']);
        $id = $this->postJson('/api/v1/platform/billing/quotations', $this->body(['tenant_id' => $tenant->id]))->json('data.id');

        $this->putJson("/api/v1/platform/billing/quotations/{$id}", $this->body(['status' => 'sent', 'lines' => [['name' => 'Only line', 'quantity' => 1, 'unit_price' => 10]]]))
            ->assertOk()->assertJsonPath('data.number', 'Q-2026-0001')->assertJsonCount(1, 'data.lines')->assertJsonPath('data.status', 'sent');

        $this->getJson('/api/v1/platform/billing/quotations?filter[status]=sent')->assertOk()->assertJsonPath('data.0.customer.name', 'Alpha School');
        $this->getJson('/api/v1/platform/billing/quotations?filter[status]=accepted')->assertOk()->assertJsonCount(0, 'data');

        $this->deleteJson("/api/v1/platform/billing/quotations/{$id}")->assertNoContent();
        $this->assertSame(0, Quotation::query()->count());
        $this->assertDatabaseCount('bo_quotation_lines', 0, config('tenancy.database.central_connection'));
    }

    public function test_the_quotation_page_shows_lines_and_totals_in_english_or_khmer(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.billing.read', 'platform.billing.manage']));
        $id = $this->postJson('/api/v1/platform/billing/quotations', $this->body(['notes' => 'Pay 50% on signing']))->json('data.id');
        $quotation = Quotation::query()->findOrFail($id);
        $export = app(QuotationExport::class);

        $en = $export->html($quotation, 'en');
        $this->assertStringContainsString('QUOTATION', $en);
        $this->assertStringContainsString('Q-2026-0001', $en);
        $this->assertStringContainsString('Academic Programs', $en);
        $this->assertStringContainsString('Discount (10%)', $en);
        $this->assertStringContainsString('$278.31', $en);
        $this->assertStringContainsString('Pay 50% on signing', $en);

        $km = $export->html($quotation, 'km');
        $this->assertStringContainsString('សម្រង់តម្លៃ', $km);
        $this->assertStringContainsString('សរុប', $km);
    }

    public function test_the_quotation_downloads_as_excel_and_needs_a_known_format(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.billing.read', 'platform.billing.manage']));
        $id = $this->postJson('/api/v1/platform/billing/quotations', $this->body())->json('data.id');

        $response = $this->get("/api/v1/platform/billing/quotations/{$id}/export?format=xlsx&lang=km")->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Q-2026-0001.xlsx', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', $response->getContent());
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'billing.quotation_exported']);

        $this->getJson("/api/v1/platform/billing/quotations/{$id}/export?format=docx")->assertUnprocessable();
    }

    public function test_the_quotation_downloads_as_pdf_and_image(): void
    {
        if (! file_exists(config('services.browsershot.chrome_path') ?: '/usr/bin/chromium')) {
            $this->markTestSkipped('Chromium is not available in this environment — see docker/php/Dockerfile.');
        }
        $this->actingAsStaff($this->platformStaff(['platform.billing.read', 'platform.billing.manage']));
        $id = $this->postJson('/api/v1/platform/billing/quotations', $this->body())->json('data.id');

        $pdf = $this->get("/api/v1/platform/billing/quotations/{$id}/export?format=pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $png = $this->get("/api/v1/platform/billing/quotations/{$id}/export?format=png&lang=km")->assertOk();
        $this->assertSame('image/png', $png->headers->get('Content-Type'));
        $this->assertStringStartsWith("\x89PNG", $png->getContent());
    }

    public function test_a_quotation_needs_lines_and_viewing_alone_cannot_save(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.billing.read', 'platform.billing.manage']));
        $this->postJson('/api/v1/platform/billing/quotations', $this->body(['lines' => []]))->assertUnprocessable()->assertJsonValidationErrors('lines');

        $this->actingAsStaff($this->platformStaff(['platform.billing.read']));
        $this->postJson('/api/v1/platform/billing/quotations', $this->body())->assertNotFound();
    }
}
