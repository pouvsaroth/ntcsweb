<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenant-owned. Someone to call if something happens to a staff member (HRM > Staff > Emergency Contacts). */
#[Fillable(['staff_id', 'name', 'relationship', 'phone', 'alt_phone', 'address', 'note'])]
class StaffEmergencyContact extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public const RELATIONSHIPS = ['father', 'mother', 'spouse', 'sibling', 'child', 'relative', 'friend', 'other'];

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
        return (string) $this->name;
    }
}
