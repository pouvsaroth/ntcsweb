<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StudentFeedbackReplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A message in a StudentFeedback thread — see the migration's docblock.
 * Deliberately not Auditable, same reasoning as ProjectTaskComment: a
 * message is already its own visible record. Always created through
 * StudentFeedback::addReply(), never directly, so the parent's `status`
 * stays in sync.
 *
 * @property int $student_feedback_id
 * @property int $user_id
 * @property string $body
 */
#[Fillable(['student_feedback_id', 'user_id', 'body'])]
class StudentFeedbackReply extends Model
{
    use HasFactory, SoftDeletes;

    /** @use HasFactory<StudentFeedbackReplyFactory> */
    protected $connection = 'tenant';
    public function studentFeedback(): BelongsTo
    {
        return $this->belongsTo(StudentFeedback::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
