<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Models\BillingSettings;
use App\Platform\Models\Plan;
use App\Platform\Support\PlatformAudit;
use App\Services\Pdf\PdfImageEncoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Billing settings: trial, grace and warning days, the trial plan, the receipt header (stage 5). */
final class BillingSettingsController extends Controller
{
    private const FIELDS = [
        'trial_days', 'grace_days', 'warning_days', 'trial_plan_id',
        'company_name', 'company_address', 'company_phone', 'company_email', 'company_tax_number', 'company_logo',
    ];

    public function __construct(private readonly PlatformAudit $audit) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->present(BillingSettings::current()));
    }

    /** The receipt logo: PNG/JPEG/WebP, up to 1 MB, kept on the private disk. */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024']]);

        $settings = BillingSettings::current();
        $old = $settings->company_logo;
        $path = $request->file('logo')->store('platform/billing', 'local');

        DB::connection($settings->getConnectionName())->transaction(function () use ($settings, $path, $old) {
            $settings->update(['company_logo' => $path]);
            $this->audit->record('billing.logo_changed', $settings, ['company_logo' => $old], ['company_logo' => $path]);
        });
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return ApiResponse::success($this->present($settings->fresh()));
    }

    public function removeLogo(): JsonResponse
    {
        $settings = BillingSettings::current();
        $old = $settings->company_logo;

        if ($old) {
            DB::connection($settings->getConnectionName())->transaction(function () use ($settings, $old) {
                $settings->update(['company_logo' => null]);
                $this->audit->record('billing.logo_changed', $settings, ['company_logo' => $old], ['company_logo' => null]);
            });
            Storage::disk('local')->delete($old);
        }

        return ApiResponse::success($this->present($settings->fresh()));
    }

    /** @return array<string, mixed> */
    private function present(BillingSettings $settings): array
    {
        return $settings->only(self::FIELDS) + [
            'company_logo_data' => PdfImageEncoder::dataUri($settings->logoPath()),
            // Online payment for website sign-ups (stage 8c). The token is never sent back.
            'khqr_template' => $settings->khqr_template,
            'bakong_token_set' => filled($settings->bakong_token),
            'bakong_token_expires_at' => $settings->bakong_token_expires_at?->toIso8601String(),
        ];
    }

    /** NewTech's KHQR and the Bakong token (stage 8c). An empty token field keeps the saved one. */
    public function updatePayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'khqr_template' => ['sometimes', 'nullable', 'string', 'max:1000', function (string $attr, mixed $value, \Closure $fail) {
                if (filled($value)) {
                    try {
                        \App\Support\Billing\Khqr::withAmount(trim((string) $value), 1, 'USD');
                    } catch (\InvalidArgumentException) {
                        $fail(__('This does not look like a KHQR code. Paste the text of your bank app\'s "receive any amount" QR.'));
                    }
                }
            }],
            'bakong_token' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'clear_token' => ['sometimes', 'boolean'],
        ]);

        $settings = BillingSettings::current();
        $changes = [];
        if (array_key_exists('khqr_template', $data)) {
            $changes['khqr_template'] = filled($data['khqr_template']) ? trim($data['khqr_template']) : null;
        }
        if (filled($data['bakong_token'] ?? null)) {
            $token = trim($data['bakong_token']);
            $changes['bakong_token'] = $token;
            $changes['bakong_token_expires_at'] = \App\Platform\Signup\Bakong::expiryOf($token);
        } elseif ($request->boolean('clear_token')) {
            $changes['bakong_token'] = null;
            $changes['bakong_token_expires_at'] = null;
        }

        DB::connection($settings->getConnectionName())->transaction(function () use ($settings, $changes) {
            $old = ['khqr_template' => $settings->khqr_template, 'bakong_token_set' => filled($settings->bakong_token)];
            $settings->update($changes);
            // The token itself never goes into the audit log.
            $this->audit->record('billing.payment_settings_updated', $settings, $old, ['khqr_template' => $settings->khqr_template, 'bakong_token_set' => filled($settings->bakong_token)]);
        });

        return ApiResponse::success($this->present($settings->fresh()));
    }

    /** "Test the token": asks Bakong about a code nobody paid. */
    public function testBakong(\App\Platform\Signup\Bakong $bakong): JsonResponse
    {
        return ApiResponse::success($bakong->test());
    }

    public function update(Request $request): JsonResponse
    {
        $connection = (new Plan)->getConnectionName();
        $data = $request->validate([
            'trial_days' => ['sometimes', 'integer', 'between:1,365'],
            'grace_days' => ['sometimes', 'integer', 'between:0,90'],
            'warning_days' => ['sometimes', 'integer', 'between:1,90'],
            'trial_plan_id' => ['sometimes', 'nullable', 'integer', Rule::exists($connection.'.bo_plans', 'id')->where('status', Plan::STATUS_ACTIVE)],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'company_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'company_tax_number' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $settings = BillingSettings::current();
        $old = $settings->only(array_keys($data));

        DB::connection($connection)->transaction(function () use ($settings, $data, $old) {
            $settings->update($data);
            $this->audit->record('billing.settings_updated', $settings, $old, $settings->only(array_keys($data)));
        });

        return ApiResponse::success($this->present($settings->fresh()));
    }
}
