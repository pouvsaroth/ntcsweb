<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A private work document of a teacher — contract, certificate, ID … (HRM > Teacher > Documents). Stored on the private disk.
 * See docs/hrm/teachers.md.
 */
#[Fillable(['staff_id', 'type', 'title', 'file_path', 'file_name', 'mime', 'size', 'expires_on', 'note', 'uploaded_by'])]
class TeacherDocument extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public const TYPES = ['contract', 'certificate', 'id_card', 'cv', 'other'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'expires_on' => 'date',
        ];
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
        return (string) $this->title;
    }
}
