<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SchoolNetwork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * HRM > Attendance & Time > Geofences > School Wi-Fi: the school's internet
 * addresses — on one of them a check-in / out needs no location. Same
 * permissions as the geofences (staff-attendance.view, geofences.*).
 */
final class SchoolNetworkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SchoolNetwork::class);

        $ip = $request->ip();

        return ApiResponse::success(SchoolNetwork::query()->orderBy('name')->get()->map(fn (SchoolNetwork $network) => $this->data($network, $ip))->all());
    }

    /** The address this device reaches the server from, for "Add this network". */
    public function current(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SchoolNetwork::class);

        $ip = (string) $request->ip();
        $match = SchoolNetwork::query()->orderBy('name')->get()->first(fn (SchoolNetwork $network) => $network->matches($ip));

        return ApiResponse::success([
            'ip' => $ip,
            'ip_range' => SchoolNetwork::rangeFor($ip),
            'network' => $match !== null ? $this->data($match, $ip) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', [SchoolNetwork::class, SchoolNetwork::class]);

        $network = SchoolNetwork::query()->create($this->validated($request));

        return ApiResponse::created($this->data($network, $request->ip()));
    }

    public function update(Request $request, SchoolNetwork $schoolNetwork): JsonResponse
    {
        $this->authorize('update', $schoolNetwork);

        $schoolNetwork->update($this->validated($request));

        return ApiResponse::success($this->data($schoolNetwork, $request->ip()));
    }

    public function destroy(SchoolNetwork $schoolNetwork): JsonResponse
    {
        $this->authorize('delete', $schoolNetwork);

        $schoolNetwork->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function data(SchoolNetwork $network, ?string $ip): array
    {
        return [
            'id' => $network->id,
            'name' => $network->name,
            'ip_range' => $network->ip_range,
            'is_active' => (bool) $network->is_active,
            'note' => $network->note,
            // This device is on it now.
            'is_current' => $network->matches($ip),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ip_range' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail) {
                if (! self::validRange((string) $value)) {
                    $fail(__('Enter an internet address like 203.0.113.7, or a range like 203.0.113.0/29.'));
                }
            }],
            'is_active' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return [...$data, 'ip_range' => trim($data['ip_range'])];
    }

    private static function validRange(string $range): bool
    {
        $range = trim($range);
        [$address, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        $max = str_contains($address, ':') ? 128 : 32;
        // Wider than a /16 (IPv4) or /48 (IPv6) would let in half a city.
        $min = $max === 128 ? 48 : 16;

        return ctype_digit($bits) && (int) $bits >= $min && (int) $bits <= $max && IpUtils::checkIp($address, $range);
    }
}
