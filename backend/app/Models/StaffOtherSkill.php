<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenant-owned. Any other skill of a staff member, e.g. first aid or driving (HRM > Staff > Other Skills). */
#[Fillable(['staff_id', 'skill', 'level', 'note'])]
class StaffOtherSkill extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public const LEVELS = ['basic', 'intermediate', 'advanced', 'expert'];

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function auditModule(): string
    {
        return 'HRM';
    }

    public function auditDisplayName(): string
    {
        return (string) $this->skill;
    }
}
