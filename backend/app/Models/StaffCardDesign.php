<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant-owned. HRM > Staff cards: one card design — its size in mm, a
 * background photo per side and the fields placed on each side. A field is
 * {id, type, x, y, w, h (mm), font_size (pt), color, bold, align, text?, shape?}.
 */
#[Fillable(['name', 'width_mm', 'height_mm', 'front_background_path', 'back_background_path', 'signature_path', 'front_fields', 'back_fields', 'created_by'])]
class StaffCardDesign extends Model
{
    use Auditable, HasFactory;

    /** What can be placed on a card. Text ones take font settings; the rest are image boxes. */
    public const TEXT_FIELDS = [
        'full_name', 'other_name', 'employee_code', 'position', 'department',
        'phone', 'email', 'gender', 'date_of_birth', 'hire_date', 'expiry_date', 'text',
    ];

    public const IMAGE_FIELDS = ['photo', 'qr', 'logo', 'signature'];

    public const ALIGNS = ['left', 'center', 'right'];

    public const SHAPES = ['rect', 'rounded', 'circle'];

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'width_mm' => 'decimal:2',
            'height_mm' => 'decimal:2',
            'front_fields' => 'array',
            'back_fields' => 'array',
        ];
    }

    public static function fieldTypes(): array
    {
        return [...self::TEXT_FIELDS, ...self::IMAGE_FIELDS];
    }

    public function url(?string $path): ?string
    {
        return $path !== null ? Storage::disk('public')->url($path) : null;
    }

    public function auditModule(): string
    {
        return 'HRM';
    }
}
