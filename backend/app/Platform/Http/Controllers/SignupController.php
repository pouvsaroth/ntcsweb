<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Models\Signup;
use App\Platform\Signup\Signups;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Billing → Sign-ups: who signed up on the website, and what became of it (docs/backoffice/stage-8-signup.md). */
final class SignupController extends Controller
{
    public function __construct(private readonly Signups $signups) {}

    /** Paid (the right amount), but the school could not be created: try again once the cause is fixed. */
    public function retry(Signup $signup): JsonResponse
    {
        abort_unless($signup->status === Signup::FAILED && $signup->bakong_hash !== null && $signup->tenant_id === null
            && $signup->paid_currency === $signup->currency && (float) $signup->paid_amount + 0.001 >= (float) $signup->amount, 409, __('Only a paid sign-up whose school was not created can be tried again.'));

        $signup->forceFill(['status' => Signup::PAID, 'failure' => null])->save();
        $signup = $this->signups->createPaidSchool($signup);

        return ApiResponse::success(['status' => $signup->status, 'failure' => $signup->failure, 'school_id' => $signup->tenant_id]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Signup::query()->with(['plan:id,code,name', 'tenant:id,name,slug,status', 'product:id,code,name,name_km']);

        $page = ApiQuery::for($query, $request)
            ->searchable('school_name', 'school_name_en', 'slug', 'owner_name', 'owner_email', 'owner_phone')
            ->filterable(['status', 'kind'])
            ->sortable(['created_at', 'amount'], default: '-id')
            ->paginate();

        $page->through(fn (Signup $s) => [
            'id' => $s->id,
            'kind' => $s->kind,
            'system' => $s->product?->only(['id', 'code', 'name', 'name_km']),
            'status' => $s->status === Signup::PENDING_PAYMENT && $s->expires_at?->copy()->addMinutes((int) config('platform.bakong.late_minutes', 10))->isPast() ? Signup::EXPIRED : $s->status,
            'plan' => $s->plan?->only(['id', 'code', 'name']),
            'periods' => $s->periods,
            'amount' => $s->amount,
            'currency' => $s->currency,
            'school_name' => $s->school_name,
            'school_name_en' => $s->school_name_en,
            'slug' => $s->slug,
            'owner_name' => $s->owner_name,
            'owner_email' => $s->owner_email,
            'owner_phone' => $s->owner_phone,
            'school' => $s->tenant?->only(['id', 'name', 'slug', 'status']),
            'failure' => $s->failure,
            'ip_address' => $s->ip_address,
            'paid_at' => $s->paid_at?->toIso8601String(),
            'paid_amount' => $s->paid_amount,
            'bakong_hash' => $s->bakong_hash,
            'can_retry' => $s->status === Signup::FAILED && $s->bakong_hash !== null && $s->tenant_id === null
                && $s->paid_currency === $s->currency && (float) $s->paid_amount + 0.001 >= (float) $s->amount,
            'created_at' => $s->created_at?->toIso8601String(),
        ]);

        return ApiResponse::success($page);
    }
}
