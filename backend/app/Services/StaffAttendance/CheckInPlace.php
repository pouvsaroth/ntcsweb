<?php

declare(strict_types=1);

namespace App\Services\StaffAttendance;

/** Where a check-in / out was allowed: on a school Wi-Fi or inside a zone, and its name. */
final class CheckInPlace
{
    public const LOCATION = 'location';

    public const WIFI = 'wifi';

    public function __construct(public readonly string $via, public readonly string $name) {}
}
