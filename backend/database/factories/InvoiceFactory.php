<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Student;
use App\Models\Tenant;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'invoice_number' => 'INV-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'student_id' => Student::factory(),
            'invoice_date' => now()->toDateString(),
            'due_date' => null,
            'status' => InvoiceStatus::ISSUED,
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
            'currency' => 'USD',
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Invoice $invoice) use ($tenant) {
            $invoice->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forStudent(Student $student): static
    {
        return $this->state(['student_id' => $student->getKey()]);
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
