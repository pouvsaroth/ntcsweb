<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ApprovalGroup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and update share one shape — `user_ids` is always the group's
 * whole member list, replacing whatever it had (an empty list = no members).
 * Only users of the current school can be added.
 */
class SaveApprovalGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $group = $this->route('approval_group');

        return $group instanceof ApprovalGroup
            ? ($this->user()?->can('update', $group) ?? false)
            : ($this->user()?->can('create', ApprovalGroup::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('tenant_id', app(TenantContext::class)->id())],
        ];
    }
}
