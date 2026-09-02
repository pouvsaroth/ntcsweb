<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProgramOffering;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProgramOffering
 */
class ProgramOfferingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_program_id' => $this->academic_program_id,
            'academic_program' => $this->whenLoaded('academicProgram', fn () => $this->academicProgram !== null ? ['id' => $this->academicProgram->id, 'code' => $this->academicProgram->code, 'name' => $this->academicProgram->name] : null),
            'study_mode_id' => $this->study_mode_id,
            'study_mode' => $this->whenLoaded('studyMode', fn () => $this->studyMode !== null ? ['id' => $this->studyMode->id, 'code' => $this->studyMode->code, 'name' => $this->studyMode->name] : null),
            'academic_year_id' => $this->academic_year_id,
            'academic_year' => $this->whenLoaded('academicYear', fn () => $this->academicYear !== null ? ['id' => $this->academicYear->id, 'name' => $this->academicYear->name] : null),
            'name' => $this->auditDisplayName(),
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
