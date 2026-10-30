<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenant-owned. A staff member's spouse, child, parent or sibling (HRM > Staff > Family Members). */
#[Fillable(['staff_id', 'name', 'relationship', 'gender', 'date_of_birth', 'occupation', 'phone', 'note'])]
class StaffFamilyMember extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public const RELATIONSHIPS = ['spouse', 'child', 'father', 'mother', 'sibling', 'other'];

    public const GENDERS = ['male', 'female', 'other'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date'];
    }

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
