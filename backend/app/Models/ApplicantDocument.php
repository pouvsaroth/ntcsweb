<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant-owned. An applicant's CV or other file (HRM > Recruitment >
 * CV/resume), kept on the private `local` disk — never a public URL; see
 * ApplicantDocumentController::download().
 */
#[Fillable(['applicant_id', 'type', 'file_path', 'original_name', 'mime_type', 'size', 'uploaded_by'])]
class ApplicantDocument extends Model
{
    public const TYPE_CV = 'cv';

    public const TYPES = ['cv', 'cover_letter', 'certificate', 'other'];

    /** CVs and certificates arrive as PDFs, Word files or phone photos. */
    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    /** In kilobytes, for the `max:` rule. */
    public const MAX_KB = 10240;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'applicant_id' => 'integer',
            'size' => 'integer',
            'uploaded_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(fn (self $document) => Storage::disk('local')->delete($document->file_path));
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
