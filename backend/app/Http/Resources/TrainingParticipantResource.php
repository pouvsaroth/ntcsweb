<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TrainingParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff member on a training — a row of Training Records or Certificates.
 * The training is included when loaded (the record lists), left out inside
 * a training's own participant list.
 *
 * @mixin TrainingParticipant
 */
class TrainingParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $training = $this->relationLoaded('training') ? $this->training : null;

        return [
            'id' => $this->id,
            'training_id' => $this->training_id,
            'staff_id' => $this->staff_id,
            'staff' => $this->whenLoaded('staff', fn () => $this->staff ? [
                'id' => $this->staff->id,
                'name' => $this->staff->fullName(),
                'employee_code' => $this->staff->employee_code,
            ] : null),
            'training' => $this->when($training !== null, fn () => [
                'id' => $training->id,
                'title' => $training->title,
                'category' => $training->category,
                'provider_type' => $training->provider_type,
                'trainer' => $training->trainer,
                'start_date' => $training->start_date?->toDateString(),
                'end_date' => $training->end_date?->toDateString(),
                'hours' => $training->hours !== null ? (float) $training->hours : null,
                'status' => $training->status,
            ]),
            'attendance' => $this->attendance,
            'result' => $this->result,
            'score' => $this->score !== null ? (float) $this->score : null,
            'note' => $this->note,
            'certificate' => $this->hasCertificate() ? [
                'no' => $this->certificate_no,
                'issued_on' => $this->certificate_issued_on?->toDateString(),
                'expires_on' => $this->certificate_expires_on?->toDateString(),
                'expired' => $this->certificate_expires_on !== null && $this->certificate_expires_on->endOfDay()->isPast(),
                'file_name' => $this->certificate_file_name,
                'has_file' => $this->certificate_file_path !== null,
            ] : null,
        ];
    }
}
