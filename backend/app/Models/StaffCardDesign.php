<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant-owned. HRM > Staff cards, Students > Student cards and
 * Examination > Certificate designs: one card design — its size in mm, a
 * background photo per side and the fields placed on each side. A field is
 * {id, type, x, y, w, h (mm), font_size (pt), color, bold, align, text?, shape?}.
 */
#[Fillable(['card_for', 'name', 'width_mm', 'height_mm', 'has_front', 'has_back', 'front_background_path', 'back_background_path', 'signature_path', 'front_fields', 'back_fields', 'created_by'])]
class StaffCardDesign extends Model
{
    use Auditable, HasFactory;

    /** New designs have both sides unless told otherwise (matches the column defaults). */
    protected $attributes = ['has_front' => true, 'has_back' => true];

    /** Who the card is for: HRM > Staff cards or Students > Student cards. */
    public const FOR_STAFF = 'staff';

    public const FOR_STUDENT = 'student';

    /** Examination > Certificate designs: printed for students who passed the exam. */
    public const FOR_CERTIFICATE = 'certificate';

    public const CARD_FOR = [self::FOR_STAFF, self::FOR_STUDENT, self::FOR_CERTIFICATE];

    /** What can be placed on a card. Text ones take font settings; the rest are image boxes. */
    public const TEXT_FIELDS = [
        'full_name', 'other_name', 'employee_code', 'position', 'department',
        'phone', 'email', 'gender', 'date_of_birth', 'hire_date', 'expiry_date', 'text',
    ];

    /** A student card's text fields (class, course, ... from their current enrollment). */
    public const STUDENT_TEXT_FIELDS = [
        'full_name', 'english_name', 'student_code', 'class_name', 'course', 'program', 'academic_year',
        'phone', 'guardian_phone', 'gender', 'date_of_birth', 'enrollment_date', 'expiry_date', 'text',
    ];

    /**
     * A certificate's text fields — Khmer and English versions side by side,
     * from the passed exam (book, score, mention, exam date, file code) and
     * the student; the issue date in parts to sit on the printed dots.
     */
    public const CERTIFICATE_TEXT_FIELDS = [
        'name_km', 'name_en', 'gender_km', 'gender_en', 'dob_km', 'dob_en',
        'course', 'book', 'exam_date_km', 'exam_date_en', 'mention_km', 'mention_en', 'score',
        'file_code', 'student_code', 'academic_year',
        'issue_day_km', 'issue_month_km', 'issue_year_km', 'issue_date_en', 'text',
    ];

    public const IMAGE_FIELDS = ['photo', 'qr', 'logo', 'signature'];

    public const ALIGNS = ['left', 'center', 'right'];

    public const SHAPES = ['rect', 'rounded', 'circle'];

    /** Fonts a text field can use — kept in step with CARD_FONTS (frontend utils/staffCard.ts). */
    public const FONTS = ['Battambang', 'Kantumruy Pro', 'Hanuman', 'Suwannaphum', 'Nokora', 'Noto Serif Khmer', 'Content', 'Siemreap', 'Moul', 'Moulpali', 'Koulen', 'Bokor', 'Dangrek', 'Preahvihear', 'Angkor', 'Freehand', 'Fasthand', 'Roboto', 'Open Sans', 'Montserrat', 'Poppins', 'Lato', 'Oswald', 'Playfair Display', 'Merriweather', 'Arial', 'Times New Roman'];

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'width_mm' => 'decimal:2',
            'height_mm' => 'decimal:2',
            'front_fields' => 'array',
            'back_fields' => 'array',
            'has_front' => 'boolean',
            'has_back' => 'boolean',
        ];
    }

    public static function fieldTypes(string $cardFor = self::FOR_STAFF): array
    {
        $text = match ($cardFor) {
            self::FOR_STUDENT => self::STUDENT_TEXT_FIELDS,
            self::FOR_CERTIFICATE => self::CERTIFICATE_TEXT_FIELDS,
            default => self::TEXT_FIELDS,
        };

        return [...$text, ...self::IMAGE_FIELDS];
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
