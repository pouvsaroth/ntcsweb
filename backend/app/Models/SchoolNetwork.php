<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Tenant-owned. A school Wi-Fi network, known by the internet address its
 * phones reach the server from: one address or a CIDR range (an IPv6 Wi-Fi
 * gives every phone its own address inside one /64). A check-in / out from
 * an active network's address needs no location (GeofenceService).
 */
#[Fillable(['name', 'ip_range', 'is_active', 'note'])]
class SchoolNetwork extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function matches(?string $ip): bool
    {
        return $ip !== null && $ip !== '' && IpUtils::checkIp($ip, $this->ip_range);
    }

    /** What to save for "Add this network": the address itself, or its /64 on IPv6. */
    public static function rangeFor(string $ip): string
    {
        if (! str_contains($ip, ':')) {
            return $ip;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }

    public function auditModule(): string
    {
        return 'Attendance';
    }
}
