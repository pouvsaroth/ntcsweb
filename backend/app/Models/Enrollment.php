<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. Student <-> Class <-> Book: which specific book a student
 * picked from that class session's book menu (`SchoolClass::books()`), and
 * what they're being charged for it. Modelled as a full Eloquent model (its
 * own `id`, queryable on its own) rather than a `belongsToMany` pivot, so it
 * can carry that metadata and be looked up directly without always going
 * through one side's relation.
 *
 * A class is a shared session (teacher, room, schedule), not a shared
 * curriculum — two students in the same session can each be on a different
 * book at a different fee, so `book_id`/`fee` live here, per student, rather
 * than on the class itself. `fee` is a snapshot taken at enrollment time
 * (see StoreEnrollmentRequest), not a live read of `books.fee` — a later
 * catalog price change must never retroactively alter what an
 * already-enrolled student owes.
 *
 * @property int $tenant_id
 * @property int $student_id
 * @property int $class_id
 * @property int $book_id
 * @property string $fee
 * @property string $status
 */
#[Fillable(['student_id', 'class_id', 'book_id', 'enrolled_at', 'fee', 'status'])]
class Enrollment extends Model
{
    use BelongsToTenant, HasFactory;

    /** @use HasFactory<EnrollmentFactory> */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DROPPED = 'dropped';

    /** PHP-level mirror of the column's DB default — see Teacher for why. */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'date',
            'fee' => 'decimal:2',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }
}
