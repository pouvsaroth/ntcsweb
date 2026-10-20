<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One self-service sign-up from the website (docs/backoffice/stage-8-signup.md).
 */
class Signup extends Model
{
    public const KIND_TRIAL = 'trial';

    public const KIND_PAID = 'paid';

    public const PENDING_PAYMENT = 'pending_payment';

    public const PAID = 'paid';

    public const CREATED = 'created';

    public const EXPIRED = 'expired';

    public const FAILED = 'failed';

    public const STATUSES = [self::PENDING_PAYMENT, self::PAID, self::CREATED, self::EXPIRED, self::FAILED];

    /** How long an unpaid sign-up holds its web address. */
    public const HOLD_MINUTES = 30;

    protected $table = 'bo_signups';

    protected $guarded = [];

    protected $hidden = ['owner_password'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'periods' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Unpaid and still holding its web address. */
    public function scopeHolding(Builder $query): void
    {
        $query->where('status', self::PENDING_PAYMENT)->where('expires_at', '>', now());
    }
}
