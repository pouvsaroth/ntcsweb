<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OfferLetter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Carries the applicant's details too — the printed letter and the Hire
 * button's pre-filled staff form both need them.
 *
 * @mixin OfferLetter
 */
class OfferLetterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference(),
            'applicant_id' => $this->applicant_id,
            'applicant' => $this->whenLoaded('applicant', fn () => $this->applicant !== null ? [
                'id' => $this->applicant->id,
                'first_name' => $this->applicant->first_name,
                'last_name' => $this->applicant->last_name,
                'name' => $this->applicant->fullName(),
                'gender' => $this->applicant->gender,
                'date_of_birth' => $this->applicant->date_of_birth?->toDateString(),
                'phone' => $this->applicant->phone,
                'email' => $this->applicant->email,
                'address' => $this->applicant->address,
                'stage' => $this->applicant->stage,
            ] : null),
            'job_position_id' => $this->job_position_id,
            'job_position' => $this->whenLoaded('jobPosition', fn () => $this->jobPosition !== null ? [
                'id' => $this->jobPosition->id,
                'reference' => $this->jobPosition->reference(),
                'title' => $this->jobPosition->title,
                'position_id' => $this->jobPosition->position_id,
                'branch_id' => $this->jobPosition->branch_id,
            ] : null),
            'position_title' => $this->position_title,
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $this->department?->name),
            'employment_type' => $this->employment_type,
            'salary' => (float) $this->salary,
            'salary_currency' => $this->salary_currency,
            'start_date' => $this->start_date?->toDateString(),
            'probation_months' => $this->probation_months,
            'expires_on' => $this->expires_on?->toDateString(),
            'benefits' => $this->benefits,
            'terms' => $this->terms,
            'status' => $this->status,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'responded_at' => $this->responded_at?->toIso8601String(),
            'hired_staff_id' => $this->hired_staff_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
