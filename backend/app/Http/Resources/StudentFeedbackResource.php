<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StudentFeedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared by both the admin queue and student self-service ("my feedback") —
 * the same shape is useful to both; the `student`/`teacher`/`replies`
 * blocks are simply omitted when the caller didn't eager-load them.
 *
 * @mixin StudentFeedback
 */
class StudentFeedbackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'student_code' => $this->student->student_code,
                'name' => $this->student->fullName(),
            ]),
            'type' => $this->type,
            'topic' => $this->topic,
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher === null ? null : [
                'id' => $this->teacher->id,
                'name' => $this->teacher->fullName(),
            ]),
            'subject' => $this->subject,
            'message' => $this->message,
            'status' => $this->status,
            'replies' => StudentFeedbackReplyResource::collection($this->whenLoaded('replies')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
