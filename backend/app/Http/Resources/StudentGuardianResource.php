<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StudentGuardian;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StudentGuardian
 */
class StudentGuardianResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'guardian_name' => $this->guardian_name,
            'guardian_type' => $this->guardian_type,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'remark' => $this->remark,
        ];
    }
}
