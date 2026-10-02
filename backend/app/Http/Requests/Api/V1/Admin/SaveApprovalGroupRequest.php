<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ApprovalGroup;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Create and update share one shape — `user_ids` is always the group's
 * whole member list, replacing whatever it had (an empty list = no members).
 * Only working staff of the current school can be added.
 */
class SaveApprovalGroupRequest extends FormRequest
{
    /** @var Collection<int, int>|null */
    private ?Collection $allowedUserIds = null;

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
            'user_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where('tenant_id', app(TenantContext::class)->id()),
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! $this->allowedUserIds()->contains((int) $value)) {
                        $fail('Only staff can be added to an approval group.');
                    }
                },
            ],
        ];
    }

    /**
     * Working staff — plus, when editing, the group's current members, so a
     * member who has since left the school can stay until someone removes
     * them rather than blocking every other edit to the group.
     *
     * @return Collection<int, int>
     */
    private function allowedUserIds(): Collection
    {
        if ($this->allowedUserIds === null) {
            $group = $this->route('approval_group');
            $current = $group instanceof ApprovalGroup ? $group->members()->pluck('user_id')->map(fn ($id) => (int) $id) : collect();
            $this->allowedUserIds = ApprovalGroup::eligibleUserIds()->merge($current)->unique()->values();
        }

        return $this->allowedUserIds;
    }
}
