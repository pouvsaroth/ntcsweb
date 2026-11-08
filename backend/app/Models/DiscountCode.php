<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\DiscountCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned. Invoices > Discount codes: money off a student's fee when
 * they enroll. Public = anyone; personal = only the `students` given it. No
 * `coursePackages` = every course. Uses are DiscountCodeRedemption rows.
 *
 * @property string $code
 * @property string $audience
 * @property string $discount_type
 * @property string $value
 * @property string|null $max_discount
 * @property Carbon $starts_on
 * @property Carbon $expires_on
 * @property int|null $max_uses
 * @property string $status
 */
#[Fillable(['code', 'name', 'audience', 'discount_type', 'value', 'max_discount', 'currency', 'starts_on', 'expires_on', 'max_uses', 'status', 'notes', 'created_by'])]
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
            'max_uses' => 'integer',
        ];
    }

    public function coursePackages(): BelongsToMany
    {
        return $this->belongsToMany(CoursePackage::class, 'discount_code_course_package');
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
     * What the list shows: inactive, scheduled (not started), expired,
     * used_up (total limit reached) or active. `$uses` = applied uses.
     */
    public function effectiveStatus(int $uses, ?string $today = null): string
    {
        $today ??= now()->toDateString();

        return match (true) {
            $this->status === self::STATUS_INACTIVE => 'inactive',
            $this->starts_on->toDateString() > $today => 'scheduled',
            $this->expires_on->toDateString() < $today => 'expired',
            $this->max_uses !== null && $uses >= $this->max_uses => 'used_up',
            default => 'active',
        };
    }

    public function auditModule(): string
    {
        return 'Billing';
    }
}
