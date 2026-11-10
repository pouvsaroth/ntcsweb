<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DiscountCodeResource;
use App\Http\Responses\ApiResponse;
use App\Models\CoursePackage;
use App\Models\DiscountCode;
use App\Models\DiscountCodeRedemption;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use App\Services\Academic\EnrollmentService;
use App\Services\Billing\DiscountCodeService;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Invoices > Discount codes: add, edit and delete discount (main) codes, and
 * assign one to students: each student gets their own code
 * (STUDENTCODE_CODE_ddmmyy) that follows the main code when it is edited.
 */
final class DiscountCodeController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DiscountCode::class);

        $codes = ApiQuery::for(
            DiscountCode::query()->whereNull('parent_id')->withCount($this->counts()),
            $request,
        )
            ->searchable('code', 'name')
            ->filterable(['status', 'discount_type'])
            ->sortable(['code', 'expires_on', 'starts_on', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(DiscountCodeResource::collection($codes));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', DiscountCode::class);

        $data = $this->validated($request, null);

        $code = DiscountCode::query()->create([
            ...$data,
            'audience' => DiscountCode::AUDIENCE_PUBLIC,
            'currency' => $this->context->getOrFail()->default_currency ?: 'USD',
            'created_by' => $request->user()?->id,
        ]);

        return ApiResponse::created(new DiscountCodeResource($this->loaded($code)));
    }

    /**
     * POST /discount-codes/{discount_code}/students — "Assign discount to
     * student": each student gets their own code, STUDENTCODE_CODE_ddmmyy.
     * Students who already have one from this code keep it.
     */
    public function assign(Request $request, DiscountCode $discountCode, DiscountCodeService $discounts): JsonResponse
    {
        $this->authorize('update', $discountCode);

        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', 'distinct', Rule::exists('tenant.students', 'id')],
        ]);

        $made = $discounts->assign($discountCode, array_map('intval', $data['student_ids']), $request->user());
        foreach ($made as $code) {
            $this->notifyGiven($code, [(int) $code->students()->value('students.id')]);
        }

        return ApiResponse::success(
            new DiscountCodeResource($this->loaded($discountCode)),
            meta: ['assigned' => count($made), 'codes' => array_map(fn (DiscountCode $c) => $c->code, $made)],
        );
    }

    /**
     * DELETE /discount-codes/{discount_code}/students/{student} — take the
     * discount back from a student: their own code is deleted, or, when it
     * was used already, kept for the history but no longer theirs.
     */
    public function unassign(DiscountCode $discountCode, Student $student): JsonResponse
    {
        $this->authorize('update', $discountCode);

        DB::connection('tenant')->transaction(function () use ($discountCode, $student) {
            $codes = $discountCode->children()->whereHas('students', fn ($q) => $q->where('students.id', $student->id))->get();
            foreach ($codes as $code) {
                if ($code->redemptions()->exists()) {
                    $code->students()->detach($student->id);
                    $code->update(['status' => DiscountCode::STATUS_INACTIVE]);
                } else {
                    $code->students()->detach();
                    $code->delete();
                }
            }
        });

        return ApiResponse::success(new DiscountCodeResource($this->loaded($discountCode)));
    }

    public function show(DiscountCode $discountCode): JsonResponse
    {
        $this->authorize('view', $discountCode);

        return ApiResponse::success(new DiscountCodeResource($this->loaded($discountCode)));
    }

    public function update(Request $request, DiscountCode $discountCode, DiscountCodeService $discounts): JsonResponse
    {
        $this->authorize('update', $discountCode);

        $data = $this->validated($request, $discountCode);

        DB::connection('tenant')->transaction(function () use ($discountCode, $data, $discounts) {
            $discountCode->update($data);
            $discounts->followMain($discountCode);
        });

        return ApiResponse::success(new DiscountCodeResource($this->loaded($discountCode->refresh())));
    }

    public function destroy(DiscountCode $discountCode): JsonResponse
    {
        $this->authorize('delete', $discountCode);

        if ($discountCode->redemptions()->exists() || $discountCode->childRedemptions()->exists()) {
            return ApiResponse::error(__('This code has been used and cannot be deleted. Set it to Inactive instead.'), 422);
        }

        DB::connection('tenant')->transaction(function () use ($discountCode) {
            $discountCode->children()->get()->each(fn (DiscountCode $code) => $code->delete());
            $discountCode->delete();
        });

        return ApiResponse::noContent();
    }

    /**
     * The Assign window's picker: students matching `search`. Here so a
     * Discount codes user needs no Students permission of their own.
     */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->can('create', DiscountCode::class) || $user->can('viewAny', DiscountCode::class)), 403);

        $search = trim((string) $request->query('search', ''));

        $students = $search === '' ? collect() : Student::query()
            ->where(fn ($q) => $q
                ->where('student_code', 'ilike', "%{$search}%")
                ->orWhere('first_name', 'ilike', "%{$search}%")
                ->orWhere('last_name', 'ilike', "%{$search}%")
                ->orWhere('english_name', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%"))
            ->orderBy('first_name')
            ->limit(20)
            ->get()
            ->map(fn (Student $s) => ['id' => $s->id, 'student_code' => $s->student_code, 'name' => $s->fullName()])
            ->values();

        return ApiResponse::success(['students' => $students]);
    }

    /**
     * POST /discount-codes/check — the Enroll form's live check: what the
     * code takes off this course's fee for this student. Anyone who can
     * enroll students may check; nothing is saved.
     */
    public function check(Request $request, DiscountCodeService $discounts, EnrollmentService $enrollments): JsonResponse
    {
        abort_unless($request->user()?->can('create', Enrollment::class), 403);

        $data = $request->validate([
            'discount_code' => ['required', 'string', 'max:80'],
            'student_id' => ['nullable', 'integer', Rule::exists('tenant.students', 'id')],
            'course_package_id' => ['required', 'integer', Rule::exists('tenant.course_packages', 'id')],
            'fee_type' => ['required', Rule::in(['monthly', 'term', 'video', 'monthly_online', 'term_online'])],
        ]);

        return ApiResponse::success($discounts->checkForEnrollment(
            $data['discount_code'],
            isset($data['student_id']) ? (int) $data['student_id'] : null,
            CoursePackage::query()->findOrFail($data['course_package_id']),
            $data['fee_type'],
            $enrollments,
        ));
    }

    /**
     * POST /discount-codes/check-invoice — Record payment's live check: what
     * the code takes off what is still owed on this invoice. Nothing is saved.
     */
    public function checkInvoice(Request $request, DiscountCodeService $discounts): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permissions::PAYMENTS_CREATE), 403);

        $data = $request->validate([
            'discount_code' => ['required', 'string', 'max:80'],
            'invoice_id' => ['required', 'integer', Rule::exists('tenant.invoices', 'id')],
        ]);

        $invoice = Invoice::query()->findOrFail($data['invoice_id']);
        $balance = (float) $invoice->balance;
        ['code' => $code, 'amount' => $amount] = $discounts->quote(
            $data['discount_code'], $invoice->student_id !== null ? (int) $invoice->student_id : null, $balance, $invoice->currency,
        );

        return ApiResponse::success([
            'code' => $code->code,
            'name' => $code->name,
            'discount_type' => $code->discount_type,
            'value' => (float) $code->value,
            'amount' => $amount,
            'fee' => $balance,
            'total' => max($balance - $amount, 0),
            'currency' => $invoice->currency,
        ]);
    }

    /**
     * GET /discount-codes/available — the forms' dropdown: the student's own
     * codes that work today, or every main code when they have none. Anyone
     * who can enroll students or take payments.
     */
    public function available(Request $request, DiscountCodeService $discounts): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->can('create', Enrollment::class) || $user->hasPermission(Permissions::PAYMENTS_CREATE)), 403);

        $data = $request->validate([
            'student_id' => ['nullable', 'integer', Rule::exists('tenant.students', 'id')],
        ]);

        return ApiResponse::success($discounts->availableFor(isset($data['student_id']) ? (int) $data['student_id'] : null)
            ->map(fn (DiscountCode $c) => [
                'code' => $c->code,
                'name' => $c->name,
                'audience' => $c->audience,
                'discount_type' => $c->discount_type,
                'value' => (float) $c->value,
                'max_discount' => $c->max_discount !== null ? (float) $c->max_discount : null,
                'currency' => $c->currency,
                'expires_on' => $c->expires_on?->toDateString(),
            ])->values());
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?DiscountCode $existing): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code', $existing?->code)))]);
        $isPercent = $request->input('discount_type', $existing?->discount_type) === DiscountCode::TYPE_PERCENT;

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('tenant.discount_codes', 'code')->ignore($existing?->id)],
            'name' => ['required', 'string', 'max:255'],
            'discount_type' => ['required', Rule::in(DiscountCode::TYPES)],
            'value' => ['required', 'numeric', 'gt:0', $isPercent ? 'max:100' : 'max:9999999'],
            'max_discount' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'starts_on' => ['required', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'status' => ['sometimes', Rule::in(DiscountCode::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'code.regex' => __('Use only letters, numbers, - and _ (no spaces).'),
        ]);

        // A cap only means something on a percent code.
        if ($data['discount_type'] === DiscountCode::TYPE_AMOUNT) {
            $data['max_discount'] = null;
        }

        return $data;
    }

    /**
     * Tell each student just given a personal code (outside the transaction:
     * a notification that fails never undoes the save). Not for a code that
     * is inactive or already expired.
     *
     * @param  list<int>  $studentIds
     */
    private function notifyGiven(DiscountCode $code, array $studentIds): void
    {
        $code->refresh();
        if ($studentIds === [] || $code->status !== DiscountCode::STATUS_ACTIVE || $code->expires_on?->endOfDay()->isPast()) {
            return;
        }

        $discount = $code->discount_type === DiscountCode::TYPE_PERCENT
            ? rtrim(rtrim(number_format((float) $code->value, 2), '0'), '.').'%'
            : number_format((float) $code->value, $code->currency === 'KHR' ? 0 : 2).' '.$code->currency;

        $users = User::query()
            ->whereIn('id', Student::query()->whereIn('id', $studentIds)->whereNotNull('user_id')->pluck('user_id'))
            ->get();

        $this->notifications->notifyMany($users, NotificationType::DISCOUNT_CODE_GIVEN, [
            'code' => $code->code,
            'name' => $code->name,
            'discount' => $discount,
            'expires_on' => $code->expires_on?->format('d-m-Y') ?? '—',
        ], link: '/admin/my-discount-codes');
    }

    private function loaded(DiscountCode $code): DiscountCode
    {
        return $code->load(['children' => fn ($q) => $q->with('students')->withCount('appliedRedemptions')->orderBy('code')])->loadCount($this->counts());
    }

    /** @return array<int|string, mixed> uses of the code and of its students' own codes, and how many students have it */
    private function counts(): array
    {
        return [
            'appliedRedemptions',
            'childRedemptions as child_uses_count' => fn ($q) => $q->where('discount_code_redemptions.status', DiscountCodeRedemption::STATUS_APPLIED),
            'children as assigned_count' => fn ($q) => $q->has('students'),
        ];
    }
}
