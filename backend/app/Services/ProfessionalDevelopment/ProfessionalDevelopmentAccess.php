<?php

declare(strict_types=1);

namespace App\Services\ProfessionalDevelopment;

use App\Models\Staff;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Who may see and change what in Classroom observation and Coaching
 * (docs/hrm/professional-development.md):
 *  - professional-development.view sees everything; .manage changes it;
 *  - everyone else with a staff record (self-service, my-requests.view) sees
 *    only what they are in: observations of them or by them, coaching they
 *    give or receive.
 */
final class ProfessionalDevelopmentAccess
{
    /** @var array<int, int|null> */
    private array $staffIds = [];

    public function canViewAll(User $user): bool
    {
        return $user->hasPermission(Permissions::PROFESSIONAL_DEVELOPMENT_VIEW);
    }

    public function canManage(User $user): bool
    {
        return $user->hasPermission(Permissions::PROFESSIONAL_DEVELOPMENT_MANAGE);
    }

    /** May open the tab at all: sees everything, or sees their own. */
    public function canUse(User $user): bool
    {
        return $this->canViewAll($user) || $user->hasPermission(Permissions::MY_REQUESTS_VIEW);
    }

    public function staffId(User $user): ?int
    {
        $key = (int) $user->getKey();
        if (! array_key_exists($key, $this->staffIds)) {
            $id = Staff::query()->where('user_id', $key)->value('id');
            $this->staffIds[$key] = $id !== null ? (int) $id : null;
        }

        return $this->staffIds[$key];
    }
}
