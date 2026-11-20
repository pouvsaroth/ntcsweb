<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parent portal: one student a parent login may see. In the school's own
 * database (users are central), so user_id has no foreign key.
 *
 * @property int $user_id
 * @property int $student_id
 * @property string|null $relation
 */
#[Fillable(['user_id', 'student_id', 'relation'])]
class ParentStudent extends Model
{
    protected $connection = 'tenant';

    protected $table = 'parent_students';

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return list<int> the students this login may see */
    public static function childIdsOf(int $userId): array
    {
        return self::query()->where('user_id', $userId)->pluck('student_id')->map(fn ($id) => (int) $id)->all();
    }
}
