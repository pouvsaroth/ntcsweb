<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned, one row (see current()). Marketing > Referrals settings:
 * on / off, the main discount code each referrer gets a copy of, and how
 * many days that copy lasts (empty: the main code's own end date).
 */
#[Fillable(['enabled', 'reward_discount_code_id', 'valid_days'])]
class ReferralSetting extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $attributes = [
        'enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'valid_days' => 'integer',
        ];
    }

    /** The school's settings — the migration inserts the row; this recreates it if it was ever removed. */
    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? tap(new self(['valid_days' => 90]), fn (self $settings) => $settings->save())->refresh();
    }

    /** @return BelongsTo<DiscountCode, $this> */
    public function rewardCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class, 'reward_discount_code_id');
    }

    public function auditModule(): string
    {
        return 'Marketing';
    }
}
