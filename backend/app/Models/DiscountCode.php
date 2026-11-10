<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\DiscountCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned. Invoices > Discount codes: money off a student's fee when
 * they enroll. A main code (no parent, audience public) works for every
 * student. Assigning it to a student makes that student their own code
 * (parent = the main code, audience personal, one row in `students`),
 * named STUDENTCODE_CODE_ddmmyy. A student with their own codes uses only
 * those. No expiry = no end date. Uses are DiscountCodeRedemption rows.
 *
 * @property int|null $parent_id
 * @property string $code
 * @property string $audience
 * @property string $discount_type
 * @property string $value
 * @property string|null $max_discount
 * @property Carbon $starts_on
 * @property Carbon|null $expires_on
 * @property string $status
 */
#[Fillable(['parent_id', 'code', 'name', 'audience', 'discount_type', 'value', 'max_discount', 'currency', 'starts_on', 'expires_on', 'status', 'notes', 'created_by'])]
class DiscountCode extends Model
{
    /** @use HasFactory<DiscountCodeFactory> */
    use Auditable, HasFactory;

    public const AUDIENCE_PUBLIC = 'public';

    public const AUDIENCE_PERSONAL = 'personal';

    public const AUDIENCES = [self::AUDIENCE_PUBLIC, self::AUDIENCE_PERSONAL];

    public const TYPE_PERCENT = 'percent';

    public const TYPE_AMOUNT = 'amount';

    public const TYPES = [self::TYPE_PERCENT, self::TYPE_AMOUNT];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $connection = 'tenant';

    protected $attributes = [
        'audience' => self::AUDIENCE_PUBLIC,
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'starts_on' => 'date',
            'expires_on' => 'date',
        ];
    }

    /** The main code a student's own code was made from. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** The students' own codes made from this main code. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Uses of the students' own codes made from this main code. */
    public function childRedemptions(): HasManyThrough
    {
        return $this->hasManyThrough(DiscountCodeRedemption::class, self::class, 'parent_id', 'discount_code_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'discount_code_student')->withTimestamps();
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountCodeRedemption::class);
    }

    /** Uses still counted (not released by a cancel). */
    public function appliedRedemptions(): HasMany
    {
        return $this->redemptions()->where('status', DiscountCodeRedemption::STATUS_APPLIED);
    }

    /**
     * What the list shows: inactive, scheduled (not started), expired or active.
     */
    public function effectiveStatus(?string $today = null): string
    {
        $today ??= now()->toDateString();

        return match (true) {
            $this->status === self::STATUS_INACTIVE => 'inactive',
            $this->starts_on->toDateString() > $today => 'scheduled',
            $this->expires_on !== null && $this->expires_on->toDateString() < $today => 'expired',
            default => 'active',
        };
    }

    public function auditModule(): string
    {
        return 'Billing';
    }
}
