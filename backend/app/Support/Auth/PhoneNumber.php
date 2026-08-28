<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Minimal phone normalisation — strips formatting so "+855 12 345 678" and
 * "012-345-678" compare equal, without pulling in a full libphonenumber
 * dependency for a single-column comparison.
 *
 * Used both when a phone number is stored (so two different-looking inputs
 * for the same number don't create two distinct values) and when matching a
 * login identifier against it (so lookup and storage never drift apart).
 */
final class PhoneNumber
{
    /**
     * @return string|null null when the input doesn't look like a phone
     *                      number at all (too few digits) — this is what
     *                      lets AuthService safely OR a phone match onto an
     *                      email lookup without a numeric fragment of an
     *                      email address (e.g. "user2024@x.com") accidentally
     *                      matching a short, coincidentally similar phone.
     */
    public static function normalize(string $value): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', $value) ?? '';

        return mb_strlen(ltrim($digits, '+')) >= 6 ? $digits : null;
    }
}
