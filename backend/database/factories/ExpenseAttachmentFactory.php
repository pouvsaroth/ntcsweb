<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseAttachment>
 */
class ExpenseAttachmentFactory extends Factory
{
    protected $model = ExpenseAttachment::class;

    public function definition(): array
    {
        return [
            'expense_id' => Expense::factory(),
            'file_path' => 'expense-attachments/'.fake()->uuid().'.pdf',
            'file_name' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (ExpenseAttachment $attachment) use ($tenant) {
            $attachment->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
