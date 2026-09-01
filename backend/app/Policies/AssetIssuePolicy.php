<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AssetIssue;
use App\Models\User;
use App\Support\Authorization\Permissions;

class AssetIssuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSET_ISSUES_VIEW);
    }

    public function view(User $user, AssetIssue $issue): bool
    {
        return $user->hasPermission(Permissions::ASSET_ISSUES_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSET_ISSUES_CREATE);
    }

    public function update(User $user, AssetIssue $issue): bool
    {
        return $user->hasPermission(Permissions::ASSET_ISSUES_UPDATE);
    }

    public function resolve(User $user, AssetIssue $issue): bool
    {
        return $user->hasPermission(Permissions::ASSET_ISSUES_RESOLVE);
    }
}
