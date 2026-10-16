<?php

declare(strict_types=1);

namespace App\Platform\Support;

use App\Models\User;

/**
 * The address of a school owner's set-password link — the same whether it
 * goes out by email or is copied from the back office and sent another way
 * (Telegram, SMS…).
 *
 * Always the console (`platform.console_url`: erp.newtechkh.com in
 * production, the local school app on a developer's machine), with the school
 * in `tenant=` — the console is where every school's users sign in, and a
 * school's own subdomain may not exist.
 */
final class InviteLink
{
    public function for(User $owner, string $token): string
    {
        $query = array_filter([
            'token' => $token,
            'email' => $owner->email,
            'tenant' => $owner->tenant?->slug,
            'invite' => 1,
        ]);

        return rtrim((string) config('platform.console_url'), '/').'/reset-password?'.http_build_query($query);
    }
}
