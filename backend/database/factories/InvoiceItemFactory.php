<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    public function definition(): array
    {
        $quantity = 1;
        $unitPrice = fake()->randomFloat(2, 5, 100);

        return [
            'invoice_id' => Invoice::factory(),
            'product_id' => Product::factory(),
            'description' => fake()->words(3, true),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount' => 0,
            'subtotal' => $quantity * $unitPrice,
            'total' => $quantity * $unitPrice,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (InvoiceItem $item) use ($tenant) {
            $item->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forInvoice(Invoice $invoice): static
    {
        return $this->state(['invoice_id' => $invoice->getKey()]);
    }
}
