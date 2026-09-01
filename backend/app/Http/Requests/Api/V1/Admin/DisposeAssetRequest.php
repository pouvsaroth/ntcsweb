<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Asset;
use App\Support\Assets\DisposalMethod;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DisposeAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Asset $asset */
        $asset = $this->route('asset');

        return $this->user()?->can('dispose', $asset) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'method' => ['required', Rule::in(DisposalMethod::all())],
            'reason' => ['required', 'string', 'max:2000'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'approved_by' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
