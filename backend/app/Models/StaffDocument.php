<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A personal / HR file of a staff member — ID card, CV,
 * contract … (HRM > Staff > Documents). Stored on the private disk.
 * Teaching documents are TeacherDocument. See docs/hrm/staff-profile-tabs.md.
 */
#[Fillable(['staff_id', 'type', 'title', 'document_no', 'issued_on', 'expires_on', 'note', 'file_path', 'file_name', 'mime', 'size', 'uploaded_by'])]
class StaffDocument extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public const TYPES = ['id_card', 'passport', 'birth_certificate', 'family_book', 'cv', 'contract', 'certificate', 'work_permit', 'medical', 'other'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'issued_on' => 'date',
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
