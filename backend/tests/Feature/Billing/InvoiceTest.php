<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Student;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Authorization\Permissions;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_creating_an_invoice_computes_totals_from_items_on_the_backend(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::INVOICES_VIEW]);
        $student = Student::factory()->forTenant($this->tenant)->create();
        $book = Product::factory()->forTenant($this->tenant)->create(['price' => 10]);
        $tshirt = Product::factory()->forTenant($this->tenant)->create(['price' => 8]);

        $response = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'discount' => 5,
            'tax' => 2,
            'items' => [
                ['product_id' => $book->id, 'quantity' => 2],
                ['product_id' => $tshirt->id, 'quantity' => 1, 'discount' => 1],
            ],
        ]);

        $response->assertCreated();

        // item 1: 2 * 10 = 20; item 2: (1 * 8) - 1 = 7; subtotal = 27
        // total = subtotal(27) - invoice discount(5) + tax(2) = 24
        $response->assertJsonPath('data.subtotal', 27);
        $response->assertJsonPath('data.total', 24);
        $response->assertJsonPath('data.balance', 24);
        $response->assertJsonPath('data.status', InvoiceStatus::ISSUED);
        $response->assertJsonPath('data.invoice_number', fn ($number) => str_starts_with($number, 'INV-'.now()->year.'-'));
    }

    public function test_the_frontend_cannot_inject_arbitrary_totals(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE]);
        $student = Student::factory()->forTenant($this->tenant)->create();
        $product = Product::factory()->forTenant($this->tenant)->create(['price' => 10]);

        $response = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            // None of these exist as validation rules on StoreInvoiceRequest
            // — FormRequest drops unknown fields, so they never reach the
            // service at all.
            'subtotal' => 999999,
            'total' => 999999,
            'paid_amount' => 999999,
            'balance' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.total', 10);
        $response->assertJsonPath('data.paid_amount', 0);
    }

    public function test_invoice_items_snapshot_the_unit_price_and_ignore_later_product_price_changes(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::INVOICES_VIEW]);
        $student = Student::factory()->forTenant($this->tenant)->create();
        $product = Product::factory()->forTenant($this->tenant)->create(['price' => 50]);

        $response = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $response->assertCreated();
        $invoiceId = $response->json('data.id');

        $product->update(['price' => 5000]);

        $show = $this->getJson("/api/v1/invoices/{$invoiceId}");
        $show->assertOk();
        $show->assertJsonPath('data.total', 50);
        $show->assertJsonPath('data.items.0.unit_price', 50);
    }

    public function test_a_variant_price_override_is_used_over_the_product_price(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE]);
        $student = Student::factory()->forTenant($this->tenant)->create();
        $product = Product::factory()->forTenant($this->tenant)->create(['price' => 10]);
        $variant = $product->variants()->create(['name' => 'Large', 'price_override' => 15]);

        $response = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1]],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.total', 15);
    }

    public function test_creating_an_invoice_requires_the_invoices_create_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $student = Student::factory()->forTenant($this->tenant)->create();
        $product = Product::factory()->forTenant($this->tenant)->create();

        $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertForbidden();
    }

    public function test_a_student_can_view_only_their_own_invoice(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE]);
        $owner = Student::factory()->forTenant($this->tenant)->create();
        $other = Student::factory()->forTenant($this->tenant)->create();
        $product = Product::factory()->forTenant($this->tenant)->create(['price' => 10]);

        $invoiceResponse = $this->postJson('/api/v1/invoices', [
            'student_id' => $owner->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();
        $invoiceId = $invoiceResponse->json('data.id');

        $ownerUser = User::factory()->forTenant($this->tenant)->create();
        $owner->forceFill(['user_id' => $ownerUser->id])->save();

        $otherUser = User::factory()->forTenant($this->tenant)->create();
        $other->forceFill(['user_id' => $otherUser->id])->save();

        $this->actingAsTenantUser($ownerUser);
        $this->getJson("/api/v1/my-invoices/{$invoiceId}")->assertOk();

        $this->actingAsTenantUser($otherUser);
        $this->getJson("/api/v1/my-invoices/{$invoiceId}")->assertNotFound();

        // The generic admin invoice endpoint also refuses a non-owning student.
        $this->getJson("/api/v1/invoices/{$invoiceId}")->assertForbidden();
    }

    public function test_a_student_only_sees_their_own_invoices_in_the_my_invoices_list(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE]);
        $owner = Student::factory()->forTenant($this->tenant)->create();
        $other = Student::factory()->forTenant($this->tenant)->create();
        $product = Product::factory()->forTenant($this->tenant)->create(['price' => 10]);

        $this->postJson('/api/v1/invoices', ['student_id' => $owner->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $this->postJson('/api/v1/invoices', ['student_id' => $other->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();

        $ownerUser = User::factory()->forTenant($this->tenant)->create();
        $owner->forceFill(['user_id' => $ownerUser->id])->save();

        $this->actingAsTenantUser($ownerUser);
        $response = $this->getJson('/api/v1/my-invoices')->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_cancelling_an_invoice_requires_a_reason_and_writes_an_audit_log(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::INVOICES_CANCEL]);
        $student = Student::factory()->forTenant($this->tenant)->create();
        $product = Product::factory()->forTenant($this->tenant)->create();

        $invoiceId = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/cancel", [])->assertUnprocessable();

        $this->postJson("/api/v1/invoices/{$invoiceId}/cancel", ['reason' => 'Duplicate entry'])
            ->assertOk()
            ->assertJsonPath('data.status', InvoiceStatus::CANCELLED);

        $log = AuditLog::where('auditable_type', Invoice::class)
            ->where('auditable_id', $invoiceId)
            ->where('action', AuditAction::INVOICE_CANCELLED)
            ->firstOrFail();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertStringContainsString('Duplicate entry', $log->description);
    }

    public function test_an_invoice_is_never_hard_deleted_it_can_only_be_cancelled_or_voided(): void
    {
        $this->assertFalse(method_exists(\App\Http\Controllers\Api\V1\Admin\InvoiceController::class, 'destroy'));
    }

    public function test_a_paid_invoice_cannot_be_cancelled_without_cancelling_the_payment_first(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::INVOICES_CANCEL, Permissions::PAYMENTS_CREATE]);
        $student = Student::factory()->forTenant($this->tenant)->create();
        $product = Product::factory()->forTenant($this->tenant)->create(['price' => 10]);

        $invoiceId = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 10,
            'payment_method' => \App\Support\Billing\PaymentMethod::CASH,
        ])->assertCreated();

        $this->postJson("/api/v1/invoices/{$invoiceId}/cancel", ['reason' => 'test'])->assertUnprocessable();
    }
}
