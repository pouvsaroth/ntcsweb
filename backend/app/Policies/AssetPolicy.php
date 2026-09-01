<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Staff;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * `view()` additionally allows a Staff/Student/User currently assigned an
 * asset to see it without holding `assets.view` — identity-based, same
 * pattern as InvoicePolicy letting a student view their own invoice.
 */
class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSETS_VIEW);
    }

    public function view(User $user, Asset $asset): bool
    {
        if ($user->hasPermission(Permissions::ASSETS_VIEW)) {
            return true;
        }

        return $this->currentlyAssignedTo($asset, $user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSETS_CREATE);
    }

    public function update(User $user, Asset $asset): bool
    {
        return $user->hasPermission(Permissions::ASSETS_UPDATE);
    }

    public function delete(User $user, Asset $asset): bool
    {
        return $user->hasPermission(Permissions::ASSETS_DELETE);
    }

    public function assign(User $user, Asset $asset): bool
    {
        return $user->hasPermission(Permissions::ASSETS_ASSIGN);
    }

    public function returnAsset(User $user, Asset $asset): bool
    {
        return $user->hasPermission(Permissions::ASSETS_RETURN);
    }

    public function transfer(User $user, Asset $asset): bool
    {
        return $user->hasPermission(Permissions::ASSETS_TRANSFER);
    }

    public function retire(User $user, Asset $asset): bool
    {
        return $user->hasPermission(Permissions::ASSETS_RETIRE);
    }

    public function dispose(User $user, Asset $asset): bool
    {
        return $user->hasPermission(Permissions::ASSETS_DISPOSE);
    }

    public function markLost(User $user, Asset $asset): bool
    {
        return $user->hasPermission(Permissions::ASSETS_MARK_LOST);
    }

    public function markFound(User $user, Asset $asset): bool
    {
        return $user->hasPermission(Permissions::ASSETS_MARK_FOUND);
    }

    private function currentlyAssignedTo(Asset $asset, User $user): bool
    {
        $identities = [[User::class, $user->getKey()]];

        $staffId = Staff::query()->where('user_id', $user->getKey())->value('id');
        if ($staffId !== null) {
            $identities[] = [Staff::class, $staffId];
        }

        $studentId = $user->student?->id;
        if ($studentId !== null) {
            $identities[] = [\App\Models\Student::class, $studentId];
        }

        return AssetAssignment::query()
            ->where('asset_id', $asset->getKey())
            ->active()
            ->where(function ($query) use ($identities) {
                foreach ($identities as [$type, $id]) {
                    $query->orWhere(fn ($q) => $q->where('assignable_type', $type)->where('assignable_id', $id));
                }
            })
            ->exists();
    }
}
