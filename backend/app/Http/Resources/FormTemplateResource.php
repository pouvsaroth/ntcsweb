<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FormTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FormTemplate
 */
class FormTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'form_category_id' => $this->form_category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'order' => $this->order,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
