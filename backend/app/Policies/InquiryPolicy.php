<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Inquiry;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** Front Office > Inquiry. Adding a follow-up or linking the new student counts as updating it. */
class InquiryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::INQUIRIES_VIEW);
    }

    public function view(User $user, Inquiry $inquiry): bool
    {
        return $user->hasPermission(Permissions::INQUIRIES_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::INQUIRIES_CREATE);
    }

    public function update(User $user, Inquiry $inquiry): bool
    {
        return $user->hasPermission(Permissions::INQUIRIES_UPDATE);
    }

    public function delete(User $user, Inquiry $inquiry): bool
    {
        return $user->hasPermission(Permissions::INQUIRIES_DELETE);
    }
}
