<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\DiscountCode;
use App\Models\Referral;
use App\Models\ReferralSetting;
use App\Models\Student;
use App\Services\Marketing\ReferralService;
use App\Support\Authorization\Permissions;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Marketing > Referrals: who brought whom, the reward codes given, and the
 * settings (on / off, reward discount code, days it lasts). Also the
 * "Referred by" student search used by the Inquiry and Student forms.
 */
final class ReferralController extends Controller
{
    public function __construct(private readonly ReferralService $referrals) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permissions::REFERRALS_VIEW);

        $query = Referral::query()->with(['referrer', 'referred', 'rewardCode']);
        $term = trim((string) $request->input('search', ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $match = fn ($q) => $q->where(fn ($w) => $w->where('first_name', 'ILIKE', $like)->orWhere('last_name', 'ILIKE', $like)->orWhere('student_code', 'ILIKE', $like));
            $query->where(fn ($q) => $q->whereHas('referrer', $match)->orWhereHas('referred', $match));
        }

        $rows = ApiQuery::for($query, $request)
            ->filterable(['status', 'referrer_student_id'])
            ->sortable(['created_at', 'rewarded_on'], default: '-created_at')
            ->paginate();

        return ApiResponse::success($rows->through(fn (Referral $r) => $this->row($r)));
    }

    /** Counts for the cards above the list. */
    public function summary(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permissions::REFERRALS_VIEW);

        return ApiResponse::success([
            'total' => Referral::query()->count(),
            'pending' => Referral::query()->where('status', Referral::STATUS_PENDING)->count(),
            'rewarded' => Referral::query()->where('status', Referral::STATUS_REWARDED)->count(),
            'referrers' => Referral::query()->distinct()->count('referrer_student_id'),
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permissions::REFERRALS_VIEW);

        return ApiResponse::success($this->settingsRow(ReferralSetting::current()));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permissions::REFERRALS_UPDATE);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            // A main code (not a student's own copy).
            'reward_discount_code_id' => ['nullable', 'required_if:enabled,true', 'integer', Rule::exists('tenant.discount_codes', 'id')->whereNull('parent_id')],
            'valid_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        $settings = ReferralSetting::current();
        $settings->update($data);

        return ApiResponse::success($this->settingsRow($settings->fresh()));
    }

    /** Main discount codes to pick the reward from. */
    public function rewardOptions(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permissions::REFERRALS_VIEW);

        $codes = DiscountCode::query()->whereNull('parent_id')->orderBy('code')
            ->get(['id', 'code', 'name', 'discount_type', 'value', 'max_discount', 'currency', 'status', 'expires_on']);

        return ApiResponse::success($codes->map(fn (DiscountCode $c) => $this->codeRow($c))->values());
    }

    public function give(Request $request, Referral $referral): JsonResponse
    {
        $this->authorizePermission($request, Permissions::REFERRALS_UPDATE);

        $referral = $this->referrals->give($referral, $request->user());

        return ApiResponse::success($this->row($referral->fresh(['referrer', 'referred', 'rewardCode'])));
    }

    /** "Referred by" search, for the Inquiry and Student forms (front desk may not see the Students list). */
    public function studentOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && collect([Permissions::INQUIRIES_VIEW, Permissions::STUDENTS_VIEW, Permissions::REFERRALS_VIEW])->contains(fn ($p) => $user->hasPermission($p)), 403);

        $term = trim((string) $request->input('search', ''));
        $query = Student::query()->orderBy('first_name')->orderBy('last_name')->limit(20);
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($q) => $q->where('first_name', 'ILIKE', $like)->orWhere('last_name', 'ILIKE', $like)
                ->orWhere('english_name', 'ILIKE', $like)->orWhere('student_code', 'ILIKE', $like)->orWhere('phone', 'ILIKE', $like));
        }
        if ($request->filled('exclude')) {
            $query->whereKeyNot($request->integer('exclude'));
        }

        return ApiResponse::success($query->get()->map(fn (Student $s) => $this->studentRow($s))->values());
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission) === true, 403);
    }

    /** @return array<string, mixed> */
    private function row(Referral $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'referrer' => $r->referrer ? $this->studentRow($r->referrer) : null,
            'referred' => $r->referred ? $this->studentRow($r->referred) : null,
            'recorded_on' => $r->created_at?->toDateString(),
            'rewarded_on' => $r->rewarded_on?->toDateString(),
            'reward_code' => $r->rewardCode ? ['id' => $r->rewardCode->id, 'code' => $r->rewardCode->code, 'expires_on' => $r->rewardCode->expires_on?->toDateString()] : null,
        ];
    }

    /** @return array{id: int, name: string, student_code: string|null} */
    private function studentRow(Student $s): array
    {
        return ['id' => $s->id, 'name' => trim($s->first_name.' '.$s->last_name), 'student_code' => $s->student_code];
    }

    /** @return array<string, mixed> */
    private function codeRow(DiscountCode $c): array
    {
        return [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'discount_type' => $c->discount_type,
            'value' => (float) $c->value, 'max_discount' => $c->max_discount !== null ? (float) $c->max_discount : null,
            'currency' => $c->currency, 'status' => $c->status, 'expires_on' => $c->expires_on?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function settingsRow(ReferralSetting $s): array
    {
        $code = $s->reward_discount_code_id ? DiscountCode::query()->find($s->reward_discount_code_id) : null;

        return [
            'enabled' => $s->enabled,
            'reward_discount_code_id' => $s->reward_discount_code_id,
            'reward_code' => $code ? $this->codeRow($code) : null,
            'valid_days' => $s->valid_days,
        ];
    }
}
