<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Platform\Models\OrganizationType;
use App\Platform\Models\Signup;
use App\Platform\Signup\Signups;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Self-service sign-up on NewTech's own website (docs/backoffice/stage-8-signup.md, 8b).
 * Only on the website school (`platform.website_school`); 404 anywhere else.
 */
final class SignupController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Signups $signups,
    ) {}

    /** The organization types a visitor can pick (School, Company, Organization…). */
    public function organizationTypes(): JsonResponse
    {
        $this->websiteOnly();
        $types = OrganizationType::query()->where('status', OrganizationType::STATUS_ACTIVE)->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success($types->map(fn (OrganizationType $t) => ['id' => $t->id, 'code' => $t->code, 'words' => $t->words])->values());
    }

    /** Is this web address free? Checked as the visitor types. */
    public function slug(Request $request): JsonResponse
    {
        $this->websiteOnly();
        $slug = strtolower(trim((string) $request->query('slug', '')));
        $problem = $this->signups->slugProblem($slug, $request->query('email') ? (string) $request->query('email') : null);

        return ApiResponse::success(['slug' => $slug, 'available' => $problem === null, 'message' => $problem]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->websiteOnly();

        // A hidden field people never see; a bot that fills it gets a polite nothing.
        if (filled($request->input('website'))) {
            return ApiResponse::error(__('Please try again.'), 422, 'SIGNUP_REJECTED');
        }

        $data = $request->validate([
            'plan' => ['required', 'string', 'max:50'],
            // Which system (stage 9c); none = the school system.
            'system' => ['nullable', 'string', 'max:50'],
            'periods' => ['nullable', 'integer', 'between:1,12'],
            'school_name' => ['required', 'string', 'max:255'],
            'school_name_en' => ['nullable', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:32'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_phone' => ['required', 'string', 'max:50', 'regex:/^[0-9+\s()-]{6,}$/'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            'locale' => ['nullable', 'string', 'max:5'],
            // School, Company, Organization… (none = School).
            'organization_type_id' => ['nullable', 'integer', Rule::exists((new OrganizationType)->getConnectionName().'.bo_organization_types', 'id')->where('status', OrganizationType::STATUS_ACTIVE)],
        ]);
        $data['slug'] = strtolower(trim($data['slug']));

        $signup = $this->signups->start($data, $request->ip());

        return ApiResponse::created($this->present($signup));
    }

    /** The visitor's own sign-up, by its token: is the school ready yet? */
    public function show(string $token): JsonResponse
    {
        $this->websiteOnly();
        $signup = Signup::query()->with(['plan', 'tenant'])->where('token', $token)->firstOrFail();

        // Waiting for payment: ask Bakong now (at most every few seconds per sign-up).
        if ($signup->status === Signup::PENDING_PAYMENT) {
            try {
                $signup = $this->signups->checkPayment($signup);
            } catch (\RuntimeException $e) {
                // Bakong cannot be asked right now; the every-minute tick keeps trying.
                report($e);
            }
        }

        return ApiResponse::success($this->present($signup));
    }

    /** @return array<string, mixed> */
    private function present(Signup $signup): array
    {
        $signup->loadMissing(['plan', 'tenant', 'product']);
        $tenant = $signup->tenant;
        $status = $signup->status;
        // A payment made as the code ran out is still looked for a few minutes.
        $late = (int) config('platform.bakong.late_minutes', 10);
        if ($status === Signup::PENDING_PAYMENT && $signup->expires_at?->copy()->addMinutes($late)->isPast()) {
            $status = Signup::EXPIRED;
        }
        $paying = $status === Signup::PENDING_PAYMENT;

        return [
            'token' => $signup->token,
            'kind' => $signup->kind,
            'system' => $signup->product ? ['code' => $signup->product->code, 'name' => $signup->product->name, 'name_km' => $signup->product->name_km] : null,
            'status' => $status,
            'plan' => $signup->plan ? ['code' => $signup->plan->code, 'name' => $signup->plan->name, 'name_km' => $signup->plan->name_km] : null,
            'periods' => $signup->periods,
            'amount' => $signup->amount,
            'currency' => $signup->currency,
            'school_name' => $signup->school_name,
            'slug' => $signup->slug,
            'owner_email' => $signup->owner_email,
            'expires_at' => $signup->expires_at?->toIso8601String(),
            // The KHQR to scan while unpaid; null when online payment is not set up (then: contact us).
            'khqr' => $paying ? $signup->khqr : null,
            'paid_at' => $signup->paid_at?->toIso8601String(),
            'receipt_number' => $signup->payment_id ? \App\Platform\Models\SchoolPayment::query()->whereKey($signup->payment_id)->value('receipt_number') : null,
            // provisioning → active (ready to sign in) · failed (we fix it and tell them)
            'school_status' => $tenant?->status,
            'sign_in_url' => $tenant?->status === Tenant::STATUS_ACTIVE
                ? rtrim((string) config('platform.console_url'), '/').'/login?'.http_build_query(['tenant' => $tenant->slug])
                : null,
        ];
    }

    private function websiteOnly(): void
    {
        abort_unless($this->context->getOrFail()->slug === config('platform.website_school'), 404);
    }
}
