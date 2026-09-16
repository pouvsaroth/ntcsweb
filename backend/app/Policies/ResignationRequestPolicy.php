<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ResignationRequest;
use App\Models\User;
use App\Support\Authorization\Permissions;

class ResignationRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::RESIGNATION_REQUESTS_VIEW);
    }

    public function view(User $user, ResignationRequest $resignationRequest): bool
    {
        return $user->hasPermission(Permissions::RESIGNATION_REQUESTS_VIEW);
    }

    public function approve(User $user, ResignationRequest $resignationRequest): bool
    {
        return $user->hasPermission(Permissions::RESIGNATION_REQUESTS_APPROVE);
    }

    public function reject(User $user, ResignationRequest $resignationRequest): bool
    {
        return $user->hasPermission(Permissions::RESIGNATION_REQUESTS_REJECT);
    }
}
