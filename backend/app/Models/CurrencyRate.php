<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CurrencyRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * KHR per 1 USD, effective from a given date — see CurrencyConversionService,
 * which is the only thing that reads this table. One row per date at most
 * per tenant (`unique(tenant_id, effective_date)`); a rate stays in effect
 * for every later date until a newer row supersedes it.
 *
 * @property int $tenant_id
 * @property string $effective_date
 * @property string $khr_per_usd
 */
#[Fillable(['effective_date', 'khr_per_usd', 'created_by'])]
class CurrencyRate extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<CurrencyRateFactory> */
    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'khr_per_usd' => 'decimal:4',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditModule(): string
    {
        return 'CurrencyRates';
    }
}
