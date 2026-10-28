<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ClassroomTableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-owned. A physical table/seat within a Classroom — e.g. "Table 1"
 * in "Classroom A". See Enrollment::table() for how a student gets seated.
 *
 * @property int $classroom_id
 * @property string $name
 * @property int $sort_order
 * @property bool $allow_many_students
 * @property string|null $remark
 */
#[Fillable(['classroom_id', 'name', 'sort_order', 'allow_many_students', 'remark'])]
class ClassroomTable extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    protected $attributes = [
        'sort_order' => 0,
        'allow_many_students' => false,
    ];

    protected function casts(): array
    {
        return ['allow_many_students' => 'boolean'];
    }

    /** Several students may sit at this table, so it is never "taken". */
    public static function allowsMany(int|string|null $tableId): bool
    {
        return $tableId !== null && self::query()->whereKey($tableId)->where('allow_many_students', true)->exists();
    }

    /** @use HasFactory<ClassroomTableFactory> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'table_id');
    }
}
