<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Borrowing;
use App\Models\ExpenseEntity;
use App\Models\Lender;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Borrowing> */
class BorrowingFactory extends Factory
{
    protected $model = Borrowing::class;

    public function definition(): array
    {
        return [
            'lender_id' => Lender::factory(),
            'expense_entity_id' => ExpenseEntity::factory(),
            'amount' => 1200,
            'currency' => 'USD',
            'received_on' => '2026-11-01',
            'interest_type' => 'none',
            'interest_rate' => 0,
            'interest_period' => 'month',
            'term_months' => 12,
            'first_payment_on' => '2026-12-01',
            'status' => 'active',
        ];
    }
}
