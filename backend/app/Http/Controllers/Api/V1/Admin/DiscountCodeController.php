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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            // Each student's own expiry, keyed by student id (null = no end).
            'expires_on' => ['nullable', 'array'],
            'expires_on.*' => ['nullable', 'date', 'after_or_equal:'.$discountCode->starts_on?->toDateString()],
        ], [
            'expires_on.*.after_or_equal' => __('The expiry date cannot be before the code starts (:date).', ['date' => $discountCode->starts_on?->format('d-m-Y')]),
        ]);

        $expiries = [];
        foreach ($data['expires_on'] ?? [] as $studentId => $date) {
            $expiries[(int) $studentId] = $date ? Carbon::parse($date)->toDateString() : null;
        }

        $made = $discounts->assign($discountCode, array_map('intval', $data['student_ids']), $request->user(), $expiries);
        foreach ($made as $code) {
            $this->notifyGiven($code, [(int) $code->students()->value('students.id')]);
        }

        return ApiResponse::success(
            new DiscountCodeResource($this->loaded($discountCode)),
            meta: ['assigned' => count($made), 'codes' => array_map(fn (DiscountCode $c) => $c->code, $made)],
        );
    }

    /**
     * PATCH /discount-codes/{discount_code}/students/{student} — change one
     * student's expiry on their own code (null = no end).
     */
    public function updateStudent(Request $request, DiscountCode $discountCode, Student $student): JsonResponse
    {
        $this->authorize('update', $discountCode);

        $data = $request->validate([
            'expires_on' => ['nullable', 'date', 'after_or_equal:'.$discountCode->starts_on?->toDateString()],
        ], [
            'expires_on.after_or_equal' => __('The expiry date cannot be before the code starts (:date).', ['date' => $discountCode->starts_on?->format('d-m-Y')]),
        ]);

        $codes = $discountCode->children()->whereHas('students', fn ($q) => $q->where('students.id', $student->id))->get();
        abort_if($codes->isEmpty(), 404);
        $codes->each(fn (DiscountCode $code) => $code->update(['expires_on' => $data['expires_on'] ? Carbon::parse($data['expires_on'])->toDateString() : null]));

        return ApiResponse::success(new DiscountCodeResource($this->loaded($discountCode)));
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
     * POST /discount-codes/check-amount — Invoice by class's live check: what
     * the code takes off this total (no student: main codes only). Anyone
     * who can create invoices. Nothing is saved.
     */
    public function checkAmount(Request $request, DiscountCodeService $discounts): JsonResponse
    {
        abort_unless($request->user()?->can('create', Invoice::class), 403);

        $data = $request->validate([
            'discount_code' => ['required', 'string', 'max:80'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['required', Rule::in(['USD', 'KHR'])],
        ]);

        $fee = (float) $data['amount'];
        ['code' => $code, 'amount' => $amount] = $discounts->quote($data['discount_code'], null, $fee, $data['currency']);

        return ApiResponse::success([
            'code' => $code->code,
            'name' => $code->name,
            'discount_type' => $code->discount_type,
            'value' => (float) $code->value,
            'amount' => $amount,
            'fee' => $fee,
            'total' => max($fee - $amount, 0),
            'currency' => $data['currency'],
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
        abort_unless($user !== null && ($user->can('create', Enrollment::class) || $user->can('create', Invoice::class) || $user->hasPermission(Permissions::PAYMENTS_CREATE)), 403);

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
    /**
     * GET /discount-codes/students — Invoices > Students with discount:
     * every student holding a code that is usable today (on, started, not
     * expired), one row per student and code, with what it gives, its end
     * date, how often they used it and their studying courses.
     */
    public function studentsWithDiscount(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DiscountCode::class);
        $today = app(DiscountCodeService::class)->today();

        $codes = DiscountCode::query()
            ->where('status', DiscountCode::STATUS_ACTIVE)
            ->whereDate('starts_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $today))
            ->whereHas('students')
            ->with(['parent:id,code,name', 'students' => fn ($q) => $q->with(['enrollments' => fn ($e) => $e->active()->with(['schoolClass:id,name', 'coursePackage:id,name'])])])
            ->get();

        $uses = DiscountCodeRedemption::query()
            ->whereIn('discount_code_id', $codes->pluck('id'))
            ->where('status', DiscountCodeRedemption::STATUS_APPLIED)
            ->select('discount_code_id', 'student_id', DB::raw('COUNT(*) as uses'), DB::raw('MAX(created_at) as last_used_at'))
            ->groupBy('discount_code_id', 'student_id')
            ->get()
            ->keyBy(fn ($r) => $r->discount_code_id.'|'.$r->student_id);

        $rows = $codes->flatMap(fn (DiscountCode $code) => $code->students->map(function (Student $student) use ($code, $uses) {
            $use = $uses->get($code->id.'|'.$student->id);

            return [
                'key' => $code->id.'-'.$student->id,
                'student' => ['id' => $student->id, 'student_code' => $student->student_code, 'name' => $student->fullName(), 'phone' => $student->phone],
                'courses' => $student->enrollments->map(fn (Enrollment $e) => array_filter([
                    'course' => $e->coursePackage?->name,
                    'class' => $e->schoolClass?->name,
                ]))->values(),
                'code' => [
                    'id' => $code->id,
                    'code' => $code->code,
                    // The main code it was given from (the code itself for an older personal code).
                    'main_id' => $code->parent_id ?? $code->id,
                    'main_code' => $code->parent?->code ?? $code->code,
                    'name' => $code->parent?->name ?? $code->name,
                    'discount_type' => $code->discount_type,
                    'value' => (float) $code->value,
                    'max_discount' => $code->max_discount !== null ? (float) $code->max_discount : null,
                    'currency' => $code->currency,
                    'starts_on' => $code->starts_on->toDateString(),
                    'expires_on' => $code->expires_on?->toDateString(),
                ],
                'uses' => (int) ($use->uses ?? 0),
                'last_used_at' => $use?->last_used_at !== null ? Carbon::parse($use->last_used_at)->toIso8601String() : null,
            ];
        }))
            ->sortBy(fn (array $r) => [$r['code']['expires_on'] === null ? 1 : 0, $r['code']['expires_on'] ?? '', $r['student']['name']])
            ->values();

        return ApiResponse::success($rows, meta: ['total' => $rows->count(), 'students' => $rows->pluck('student.id')->unique()->count()]);
    }

    /**
     * GET /discount-codes/follow-ups — Students to add discount: every
     * enrollment that turned Completed and still waits for a code (oldest
     * finish first), plus the main codes that can be given now.
     */
    public function followUps(Request $request): JsonResponse
    {
        $this->authorize('update', new DiscountCode);

        $rows = Enrollment::query()
            ->where('discount_follow_up', Enrollment::FOLLOW_UP_WAITING)
            ->where('status', Enrollment::STATUS_COMPLETED)
            ->with(['student', 'coursePackage', 'schoolClass'])
            ->orderBy('completed_on')
            ->orderBy('id')
            ->get()
            ->map(fn (Enrollment $e) => [
                'enrollment_id' => $e->id,
                'student' => $e->student === null ? null : ['id' => $e->student->id, 'student_code' => $e->student->student_code, 'name' => $e->student->fullName()],
                'course' => $e->coursePackage?->name,
                'class' => $e->schoolClass?->name,
                'start_study_on' => $e->start_study_on?->toDateString(),
                'completed_on' => $e->completed_on?->toDateString(),
            ])
            ->values();

        $today = now()->toDateString();
        $codes = DiscountCode::query()
            ->whereNull('parent_id')
            ->where('status', DiscountCode::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $today))
            ->orderBy('code')
            ->get()
            ->map(fn (DiscountCode $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'discount_type' => $c->discount_type,
                'value' => (float) $c->value,
                'currency' => $c->currency,
                'starts_on' => $c->starts_on?->toDateString(),
                'expires_on' => $c->expires_on?->toDateString(),
            ])
            ->values();

        return ApiResponse::success(['rows' => $rows, 'codes' => $codes], meta: ['total' => $rows->count()]);
    }

    /**
     * POST /discount-codes/follow-ups — give the picked students their code
     * (the same as Assign: each gets their own STUDENTCODE_CODE_ddmmyy) and
     * take them off the list. Only the rows sent are touched.
     */
    public function saveFollowUps(Request $request, DiscountCodeService $discounts): JsonResponse
    {
        $this->authorize('update', new DiscountCode);

        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.enrollment_id' => ['required', 'integer', 'distinct'],
            'rows.*.discount_code_id' => ['required', 'integer', Rule::exists('tenant.discount_codes', 'id')->whereNull('parent_id')],
            'rows.*.expires_on' => ['nullable', 'date'],
        ]);

        $enrollments = Enrollment::query()
            ->whereIn('id', array_column($data['rows'], 'enrollment_id'))
            ->where('discount_follow_up', Enrollment::FOLLOW_UP_WAITING)
            ->get()
            ->keyBy('id');

        $groups = collect($data['rows'])
            ->filter(fn (array $r) => $enrollments->has((int) $r['enrollment_id']))
            ->groupBy('discount_code_id');

        // Check every expiry first, so nothing is given when one is wrong.
        foreach ($groups as $codeId => $rows) {
            $main = DiscountCode::query()->findOrFail((int) $codeId);
            foreach ($rows as $row) {
                if (! empty($row['expires_on']) && $main->starts_on !== null && Carbon::parse($row['expires_on'])->toDateString() < $main->starts_on->toDateString()) {
                    throw ValidationException::withMessages([
                        'expires_on' => __('The expiry date cannot be before the code starts (:date).', ['date' => $main->starts_on->format('d-m-Y')]),
                    ]);
                }
            }
        }

        $made = DB::connection('tenant')->transaction(function () use ($groups, $enrollments, $discounts, $request) {
            $made = [];
            foreach ($groups as $codeId => $rows) {
                $main = DiscountCode::query()->findOrFail((int) $codeId);
                $studentIds = [];
                $expiries = [];
                foreach ($rows as $row) {
                    $studentId = (int) $enrollments[(int) $row['enrollment_id']]->student_id;
                    $studentIds[] = $studentId;
                    if (! empty($row['expires_on'])) {
                        $expiries[$studentId] = Carbon::parse($row['expires_on'])->toDateString();
                    }
                }

                array_push($made, ...$discounts->assign($main, array_values(array_unique($studentIds)), $request->user(), $expiries));
                // A student who already held this code counts as given too.
                Enrollment::query()->whereIn('id', $rows->pluck('enrollment_id')->map(fn ($id) => (int) $id))
                    ->update(['discount_follow_up' => Enrollment::FOLLOW_UP_GIVEN]);
            }

            return $made;
        });

        foreach ($made as $code) {
            $this->notifyGiven($code, [(int) $code->students()->value('students.id')]);
        }

        return ApiResponse::success([
            'given' => $groups->flatten(1)->count(),
            'codes' => array_map(fn (DiscountCode $c) => $c->code, $made),
        ]);
    }

    /** POST /discount-codes/follow-ups/skip — no discount for these: take them off the list. */
    public function skipFollowUps(Request $request): JsonResponse
    {
        $this->authorize('update', new DiscountCode);

        $data = $request->validate([
            'enrollment_ids' => ['required', 'array', 'min:1'],
            'enrollment_ids.*' => ['integer'],
        ]);

        $skipped = Enrollment::query()
            ->whereIn('id', $data['enrollment_ids'])
            ->where('discount_follow_up', Enrollment::FOLLOW_UP_WAITING)
            ->update(['discount_follow_up' => Enrollment::FOLLOW_UP_SKIPPED]);

        return ApiResponse::success(['skipped' => $skipped]);
    }

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
