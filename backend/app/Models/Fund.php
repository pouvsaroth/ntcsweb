<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\FundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. Fund > Funds: one pot of the school's money in one currency.
 * `fund_no` (F00001) is given from the id on create; `fund_type` is a
 * FUND_TYPE Base Data code. The balance is money in minus money out.
 */
#[Fillable(['name', 'fund_type', 'currency', 'opened_on', 'target_amount', 'status', 'description', 'created_by'])]
class Fund extends Model
{
    /** @use HasFactory<FundFactory> */
    use Auditable, HasFactory;

    /** The Base Data category `fund_type` takes its codes from. */
    public const TYPE_CATEGORY = 'FUND_TYPE';

    public const STATUSES = ['active', 'closed'];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'opened_on' => 'date',
            'target_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Fund $fund) {
            if ($fund->fund_no === null) {
                $fund->forceFill(['fund_no' => sprintf('F%05d', $fund->id)])->saveQuietly();
            }
        });
    }

    /** @return HasMany<FundTransaction, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(FundTransaction::class);
    }

    public function auditModule(): string
    {
        return 'Fund';
    }

    public function auditDisplayName(): string
    {
        return $this->name;
    }
}
