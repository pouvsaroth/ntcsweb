<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenant-owned. A language a staff member speaks, and how well (HRM > Staff > Languages). */
#[Fillable(['staff_id', 'language', 'level', 'certificate', 'note'])]
class StaffLanguage extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public const LEVELS = ['basic', 'intermediate', 'advanced', 'fluent', 'native'];

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
        return (string) $this->language;
    }
}
