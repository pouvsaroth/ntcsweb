<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ApprovalGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A named group of users in Approval Flow → Groups — see the migration's
 * docblock. Members are ApprovalGroupMember rows rather than a
 * belongsToMany, since `users` lives in the central database and can't be
 * joined from the tenant one.
 *
 * @property string $name
 * @property string|null $description
 */
#[Fillable(['name', 'description'])]
class ApprovalGroup extends Model
{
    /** @use HasFactory<ApprovalGroupFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    /** Staff who can be added as members — still working here (suspended or departed staff can't approve anything). */
    public const MEMBER_STAFF_STATUSES = [Staff::STATUS_ACTIVE, Staff::STATUS_PROBATION, Staff::STATUS_ON_LEAVE];

    /**
     * Users who can be added to a group: only accounts linked to a staff
     * record with one of MEMBER_STAFF_STATUSES — never students or
     * accounts with no staff record.
     *
     * @return Collection<int, int>
     */
    public static function eligibleUserIds(): Collection
    {
        return Staff::query()
            ->whereIn('status', self::MEMBER_STAFF_STATUSES)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    public function members(): HasMany
    {
        return $this->hasMany(ApprovalGroupMember::class)->orderBy('id');
    }

    /** @return Collection<int, int> */
    public function memberUserIds(): Collection
    {
        return $this->members->pluck('user_id')->map(fn ($id) => (int) $id)->values();
    }

    public function auditModule(): string
    {
        return 'Approvals';
    }
}
