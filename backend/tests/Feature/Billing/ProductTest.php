<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Product;
use App\Support\Authorization\Permissions;
use App\Support\Billing\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_an_admin_can_create_a_product(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PRODUCTS_CREATE]);

        $response = $this->postJson('/api/v1/products', [
            'code' => 'BOOK-101',
            'name' => 'Grade 1 Math Book',
            'type' => ProductType::BOOK,
            'price' => 12.5,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.code', 'BOOK-101');
        $response->assertJsonPath('data.price', 12.5);
    }

    public function test_a_product_code_must_be_unique_within_the_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PRODUCTS_CREATE]);
        Product::factory()->forTenant($this->tenant)->create(['code' => 'DUP']);

        $this->postJson('/api/v1/products', [
            'code' => 'DUP',
            'name' => 'Another',
            'price' => 1,
        ])->assertUnprocessable();
    }

    public function test_creating_a_product_requires_the_products_create_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->postJson('/api/v1/products', [
            'code' => 'X',
            'name' => 'X',
            'price' => 1,
        ])->assertForbidden();
    }

    public function test_a_product_variant_can_override_its_products_price(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PRODUCTS_CREATE, Permissions::PRODUCTS_UPDATE]);
        $product = Product::factory()->forTenant($this->tenant)->create(['price' => 10]);

        $response = $this->postJson("/api/v1/products/{$product->id}/variants", [
            'name' => 'Large',
            'price_override' => 15,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Large');
        $response->assertJsonPath('data.price_override', 15);
    }

    public function test_deleting_a_product_does_not_break_invoices_that_already_reference_it(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PRODUCTS_CREATE, Permissions::PRODUCTS_DELETE, Permissions::INVOICES_CREATE]);
        $student = \App\Models\Student::factory()->forTenant($this->tenant)->create();
        $product = Product::factory()->forTenant($this->tenant)->create(['price' => 10]);

        $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        // A product referenced by an invoice item is protected by
        // restrictOnDelete at the DB level — soft-deleting it (the app-level
        // behavior) must still succeed without touching the invoice item.
        $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();
        $this->assertNotNull($product->fresh()->deleted_at);
        $this->assertDatabaseHas('invoice_items', ['product_id' => $product->id]);
    }
}
