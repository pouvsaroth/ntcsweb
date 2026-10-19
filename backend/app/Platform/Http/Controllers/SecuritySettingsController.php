<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Models\SecuritySettings;
use App\Platform\Support\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Security (as SmartGPS-V3's back office): whether staff sign in
 * with a code from an authenticator app as well as their password. Reading
 * and changing it both need platform.security.manage.
 */
final class SecuritySettingsController extends Controller
{
    public function __construct(private readonly PlatformAudit $audit) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->present(SecuritySettings::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['require_two_factor' => ['required', 'boolean']]);

        $settings = SecuritySettings::current();
        $old = $settings->require_two_factor;
        $new = (bool) $data['require_two_factor'];

        if ($old !== $new) {
            DB::connection($settings->getConnectionName())->transaction(function () use ($settings, $old, $new) {
                $settings->update(['require_two_factor' => $new]);
                $this->audit->record('security.two_factor_requirement_changed', $settings, ['require_two_factor' => $old], ['require_two_factor' => $new]);
            });
        }

        return ApiResponse::success($this->present($settings->fresh()));
    }

    /** @return array<string, bool> */
    private function present(SecuritySettings $settings): array
    {
        return ['require_two_factor' => $settings->require_two_factor];
    }
}
