<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenant-owned. Accounting > Expenses > Support: money or food someone gave towards an Expense for. A list only — no ledger posting. */
#[Fillable(['name', 'phone', 'relationship', 'support_date', 'kind', 'amount', 'currency', 'items', 'expense_entity_id', 'note', 'created_by'])]
class ExpenseSupport extends Model
{
    use Auditable;

    public const MONEY = 'money';

    public const FOOD = 'food';

    public const KINDS = [self::MONEY, self::FOOD];

    public const RELATIONSHIPS = ['relative', 'friend', 'neighbour', 'colleague', 'other'];

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'support_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function expenseEntity(): BelongsTo
    {
        return $this->belongsTo(ExpenseEntity::class);
    }

    public function auditModule(): string
    {
        return 'Accounting';
    }
}
