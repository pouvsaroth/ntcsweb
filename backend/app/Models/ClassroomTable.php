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
 */
#[Fillable(['classroom_id', 'name'])]
class ClassroomTable extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'tenant';

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
