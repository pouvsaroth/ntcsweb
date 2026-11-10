<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. Fund > Funds: one money in or money out, with who it came
 * from / was paid to (`party`) and a remark.
 */
#[Fillable(['fund_id', 'type', 'amount', 'transacted_on', 'party', 'remark', 'cash_account_id', 'transaction_id', 'created_by'])]
class FundTransaction extends Model
{
    use Auditable;

    public const IN = 'in';

    public const OUT = 'out';

    public const TYPES = [self::IN, self::OUT];

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'transacted_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /** +amount for money in, -amount for money out. */
    public function signedAmount(): float
    {
        return $this->type === self::OUT ? -(float) $this->amount : (float) $this->amount;
    }

    /** @return BelongsTo<Fund, $this> */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditModule(): string
    {
        return 'Fund';
    }
}
