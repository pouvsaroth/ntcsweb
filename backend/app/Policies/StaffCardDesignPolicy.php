<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StaffCardDesign;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** HRM > Staff cards and Students > Student cards: each kind of card has its own permissions. */
class StaffCardDesignPolicy
{
    private const PERMISSIONS = [
        StaffCardDesign::FOR_STAFF => [
            'view' => Permissions::STAFF_CARDS_VIEW,
            'create' => Permissions::STAFF_CARDS_CREATE,
            'update' => Permissions::STAFF_CARDS_UPDATE,
            'delete' => Permissions::STAFF_CARDS_DELETE,
        ],
        StaffCardDesign::FOR_STUDENT => [
            'view' => Permissions::STUDENT_CARDS_VIEW,
            'create' => Permissions::STUDENT_CARDS_CREATE,
            'update' => Permissions::STUDENT_CARDS_UPDATE,
            'delete' => Permissions::STUDENT_CARDS_DELETE,
        ],
    ];

    private function allows(User $user, string $cardFor, string $action): bool
    {
        $permission = self::PERMISSIONS[$cardFor][$action] ?? null;

        return $permission !== null && $user->hasPermission($permission);
    }

    public function viewAny(User $user, string $cardFor = StaffCardDesign::FOR_STAFF): bool
    {
        return $this->allows($user, $cardFor, 'view');
    }

    public function view(User $user, StaffCardDesign $design): bool
    {
        return $this->allows($user, $design->card_for, 'view');
    }

    public function create(User $user, string $cardFor = StaffCardDesign::FOR_STAFF): bool
    {
        return $this->allows($user, $cardFor, 'create');
    }

    public function update(User $user, StaffCardDesign $design): bool
    {
        return $this->allows($user, $design->card_for, 'update');
    }

    public function delete(User $user, StaffCardDesign $design): bool
    {
        return $this->allows($user, $design->card_for, 'delete');
    }
}
