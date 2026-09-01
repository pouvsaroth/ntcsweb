<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AssetDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A file attached to an asset — invoice, warranty, disposal paperwork, or a photo (type=PHOTO) — stored on the `public` disk, same convention as ExpenseAttachment. */
#[Fillable(['asset_id', 'type', 'file_path', 'file_name', 'mime_type', 'caption', 'uploaded_by'])]
class AssetDocument extends Model
{
    /** @use HasFactory<AssetDocumentFactory> */
    use BelongsToTenant, HasFactory;

    public const PHOTO = 'PHOTO';

    public const INVOICE = 'INVOICE';

    public const WARRANTY = 'WARRANTY';

    public const REPAIR_INVOICE = 'REPAIR_INVOICE';

    public const REPAIR_QUOTATION = 'REPAIR_QUOTATION';

    public const DISPOSAL_DOCUMENT = 'DISPOSAL_DOCUMENT';

    public const DELIVERY_DOCUMENT = 'DELIVERY_DOCUMENT';

    public const OTHER = 'OTHER';

    /** @return list<string> */
    public static function types(): array
    {
        return [
            self::PHOTO, self::INVOICE, self::WARRANTY, self::REPAIR_INVOICE,
            self::REPAIR_QUOTATION, self::DISPOSAL_DOCUMENT, self::DELIVERY_DOCUMENT, self::OTHER,
        ];
    }

    protected $attributes = [
        'type' => self::OTHER,
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }
}
