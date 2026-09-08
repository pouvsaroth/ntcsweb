<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Audit\AuditAction;
use Database\Factories\StudentFeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A student's own self-submitted request or comment, about the school in
 * general or about a specific teacher. Unlike LeaveRequest, there is no
 * approve/reject decision to make — `status` just tracks whether anyone
 * other than the student has replied yet, flipped by {@see addReply()},
 * the only place a StudentFeedbackReply is ever created for this row.
 *
 * @property int $student_id
 * @property int|null $teacher_id
 * @property string $type
 * @property string $topic
 * @property string $status
 */
#[Fillable(['student_id', 'type', 'topic', 'teacher_id', 'subject', 'message', 'status'])]
class StudentFeedback extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    /** @use HasFactory<StudentFeedbackFactory> */
    // "feedback" is uncountable, so Eloquent's auto-pluralized guess
    // ("student_feedback") would collide with the singular reading —
    // spelled out explicitly to match the migration's `student_feedbacks`.
    protected $table = 'student_feedbacks';

    protected $connection = 'tenant';

    public const TYPE_REQUEST = 'request';

    public const TYPE_COMMENT = 'comment';

    public const TOPIC_SCHOOL = 'school';

    public const TOPIC_TEACHER = 'teacher';

    public const STATUS_OPEN = 'open';

    public const STATUS_REPLIED = 'replied';

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'teacher_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(StudentFeedbackReply::class)->orderBy('created_at');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::STATUS_OPEN);
    }

    /**
     * The only way a reply is ever attached — keeps `status` in sync with
     * "has anyone other than the student themselves weighed in yet" without
     * every caller (self-service and admin controllers alike) having to
     * remember the rule.
     */
    public function addReply(User $author, string $body): StudentFeedbackReply
    {
        $reply = $this->replies()->create([
            'user_id' => $author->getKey(),
            'body' => $body,
        ]);

        if ($author->id !== $this->student?->user_id) {
            $this->update(['status' => self::STATUS_REPLIED]);
        }

        return $reply;
    }

    public function auditModule(): string
    {
        return 'Student Feedback';
    }

    public function auditDisplayName(): string
    {
        return "{$this->student?->fullName()}: {$this->subject}";
    }

    protected function auditActionForDirty(array $dirty): string
    {
        return array_key_exists('status', $dirty) ? AuditAction::STATUS_CHANGE : AuditAction::UPDATE;
    }

    protected function auditDescriptionForChange(string $action, array $old, array $new): ?string
    {
        if ($action === AuditAction::STATUS_CHANGE) {
            return "Changed feedback status from {$old['status']} to {$new['status']} for {$this->student?->fullName()}";
        }

        return null;
    }
}
