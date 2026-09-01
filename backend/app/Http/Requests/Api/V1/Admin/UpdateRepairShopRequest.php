<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\RepairShop;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRepairShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var RepairShop $repairShop */
        $repairShop = $this->route('repair_shop');

        return $this->user()?->can('update', $repairShop) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
