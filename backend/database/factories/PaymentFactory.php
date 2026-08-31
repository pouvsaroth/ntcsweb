<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Tenant;
use App\Support\Billing\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'payment_number' => 'RCPT-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'invoice_id' => Invoice::factory(),
            'student_id' => Student::factory(),
            'amount' => fake()->randomFloat(2, 10, 100),
            'payment_method' => PaymentMethod::CASH,
            'payment_date' => now()->toDateString(),
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Payment $payment) use ($tenant) {
            $payment->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forInvoice(Invoice $invoice): static
    {
        return $this->state([
            'invoice_id' => $invoice->getKey(),
            'student_id' => $invoice->student_id,
        ]);
    }
}
