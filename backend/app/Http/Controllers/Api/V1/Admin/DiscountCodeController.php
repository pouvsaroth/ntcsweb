<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DiscountCodeResource;
use App\Http\Responses\ApiResponse;
use App\Models\CoursePackage;
use App\Models\DiscountCode;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Notifications\NotificationType;
use App\Services\Academic\EnrollmentService;
use App\Services\Billing\DiscountCodeService;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Invoices > Discount codes: add, edit, give to students (personal codes)
 * and delete codes.
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
            DiscountCode::query()->with('coursePackages')->withCount(['appliedRedemptions', 'students']),
            $request,
        )
            ->searchable('code', 'name')
            ->filterable(['audience', 'status', 'discount_type'])
            ->sortable(['code', 'expires_on', 'starts_on', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(DiscountCodeResource::collection($codes));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', DiscountCode::class);

        $data = $this->validated($request, null);

        $code = DB::connection('tenant')->transaction(function () use ($data, $request) {
            $code = DiscountCode::query()->create([
                ...collect($data)->except(['course_package_ids', 'student_ids'])->all(),
                'currency' => $this->context->getOrFail()->default_currency ?: 'USD',
                'created_by' => $request->user()?->id,
            ]);
            $given = $this->syncLinks($code, $data);

            return [$code, $given];
        });
        [$code, $given] = $code;
        $this->notifyGiven($code, $given);

        return ApiResponse::created(new DiscountCodeResource($this->loaded($code)));
    }

    /**
     * POST /discount-codes/bulk — one personal code per student, each with its
     * own value (e.g. Student A 20%, Student B 10%). The other settings are
     * shared. Codes are PREFIX-STUDENTCODE, with -2, -3 ... if one is taken.
     */
    public function bulk(Request $request): JsonResponse
    {
        $this->authorize('create', DiscountCode::class);

        $request->merge(['code_prefix' => strtoupper(trim((string) $request->input('code_prefix')))]);
        $isPercent = $request->input('discount_type') === DiscountCode::TYPE_PERCENT;

        $data = $request->validate([
            'code_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'discount_type' => ['required', Rule::in(DiscountCode::TYPES)],
            'max_discount' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'starts_on' => ['required', 'date'],
            'expires_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'course_package_ids' => ['sometimes', 'array'],
            'course_package_ids.*' => ['integer', 'distinct', Rule::exists('tenant.course_packages', 'id')],
            'rows' => ['required', 'array', 'min:1', 'max:200'],
            'rows.*.student_id' => ['required', 'integer', 'distinct', Rule::exists('tenant.students', 'id')],
            'rows.*.value' => ['required', 'numeric', 'gt:0', $isPercent ? 'max:100' : 'max:9999999'],
        ], [
            'code_prefix.regex' => __('Use only letters, numbers, - and _ (no spaces).'),
            'rows.required' => __('Add at least one student.'),
            'rows.*.student_id.distinct' => __('This student is in the list twice.'),
        ]);

        $students = Student::query()->whereIn('id', collect($data['rows'])->pluck('student_id'))->get()->keyBy('id');
        $currency = $this->context->getOrFail()->default_currency ?: 'USD';

        $codes = DB::connection('tenant')->transaction(function () use ($data, $students, $currency, $request) {
            $taken = [];
            $codes = [];
            foreach ($data['rows'] as $row) {
                $student = $students->get((int) $row['student_id']);
                $code = DiscountCode::query()->create([
                    'code' => $this->freeCode($data['code_prefix'], $student, $taken),
                    'name' => $data['name'],
                    'audience' => DiscountCode::AUDIENCE_PERSONAL,
                    'discount_type' => $data['discount_type'],
                    'value' => $row['value'],
                    'max_discount' => $data['discount_type'] === DiscountCode::TYPE_PERCENT ? ($data['max_discount'] ?? null) : null,
                    'currency' => $currency,
                    'starts_on' => $data['starts_on'],
                    'expires_on' => $data['expires_on'],
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $request->user()?->id,
                ]);
                $code->coursePackages()->sync($data['course_package_ids'] ?? []);
                $code->students()->sync([$student->id]);
                $codes[] = $code;
            }

            return $codes;
        });

        foreach ($codes as $code) {
            $this->notifyGiven($code, [(int) $code->students()->value('students.id')]);
        }

        return ApiResponse::created(DiscountCodeResource::collection(collect($codes)->map(fn (DiscountCode $c) => $this->loaded($c))));
    }

    /**
     * PREFIX-STUDENTCODE (letters and numbers only), made unique.
     *
     * @param  array<string, true>  $taken  codes already made in this batch
     */
    private function freeCode(string $prefix, Student $student, array &$taken): string
    {
        $tail = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $student->student_code));
        $base = substr($prefix.'-'.($tail !== '' ? $tail : $student->id), 0, 36);

        $code = $base;
        for ($n = 2; isset($taken[$code]) || DiscountCode::query()->where('code', $code)->exists(); $n++) {
            $code = $base.'-'.$n;
        }
        $taken[$code] = true;

        return $code;
    }

    public function show(DiscountCode $discountCode): JsonResponse
    {
        $this->authorize('view', $discountCode);

        return ApiResponse::success(new DiscountCodeResource($this->loaded($discountCode)));
    }

    public function update(Request $request, DiscountCode $discountCode): JsonResponse
    {
        $this->authorize('update', $discountCode);

        $data = $this->validated($request, $discountCode);

        $given = DB::connection('tenant')->transaction(function () use ($discountCode, $data) {
            $discountCode->update(collect($data)->except(['course_package_ids', 'student_ids'])->all());

            return $this->syncLinks($discountCode, $data);
        });
        $this->notifyGiven($discountCode, $given);

        return ApiResponse::success(new DiscountCodeResource($this->loaded($discountCode->refresh())));
    }

    public function destroy(DiscountCode $discountCode): JsonResponse
    {
        $this->authorize('delete', $discountCode);

        if ($discountCode->redemptions()->exists()) {
            return ApiResponse::error(__('This code has been used and cannot be deleted. Set it to Inactive instead.'), 422);
        }

        $discountCode->delete();

        return ApiResponse::noContent();
    }

    /**
     * The form's pickers: every course, and students matching `search` (for
     * personal codes). Here so a Discount codes user needs no Students or
     * Courses permission of their own.
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

        return ApiResponse::success([
            'course_packages' => CoursePackage::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'students' => $students,
        ]);
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
            'discount_code' => ['required', 'string', 'max:40'],
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

    /** @return array<string, mixed> */
    private function validated(Request $request, ?DiscountCode $existing): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code', $existing?->code)))]);
        $isPercent = $request->input('discount_type', $existing?->discount_type) === DiscountCode::TYPE_PERCENT;

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('tenant.discount_codes', 'code')->ignore($existing?->id)],
            'name' => ['required', 'string', 'max:255'],
            'audience' => ['required', Rule::in(DiscountCode::AUDIENCES)],
            'discount_type' => ['required', Rule::in(DiscountCode::TYPES)],
            'value' => ['required', 'numeric', 'gt:0', $isPercent ? 'max:100' : 'max:9999999'],
            'max_discount' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'starts_on' => ['required', 'date'],
            'expires_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'status' => ['sometimes', Rule::in(DiscountCode::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'course_package_ids' => ['sometimes', 'array'],
            'course_package_ids.*' => ['integer', 'distinct', Rule::exists('tenant.course_packages', 'id')],
            'student_ids' => ['sometimes', 'array'],
            'student_ids.*' => ['integer', 'distinct', Rule::exists('tenant.students', 'id')],
        ], [
            'code.regex' => __('Use only letters, numbers, - and _ (no spaces).'),
        ]);

        // A cap only means something on a percent code.
        if ($data['discount_type'] === DiscountCode::TYPE_AMOUNT) {
            $data['max_discount'] = null;
        }

        $students = $data['student_ids'] ?? $existing?->students()->pluck('students.id')->all() ?? [];
        if ($data['audience'] === DiscountCode::AUDIENCE_PERSONAL && $students === []) {
            throw ValidationException::withMessages(['student_ids' => __('Pick at least one student for a personal code.')]);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int> the students just given the code
     */
    private function syncLinks(DiscountCode $code, array $data): array
    {
        if (array_key_exists('course_package_ids', $data)) {
            $code->coursePackages()->sync($data['course_package_ids']);
        }

        if ($code->audience === DiscountCode::AUDIENCE_PUBLIC) {
            $code->students()->detach();

            return [];
        }

        if (array_key_exists('student_ids', $data)) {
            return array_map('intval', $code->students()->sync($data['student_ids'])['attached']);
        }

        return [];
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
        if ($studentIds === [] || $code->status !== DiscountCode::STATUS_ACTIVE || $code->expires_on->endOfDay()->isPast()) {
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
            'expires_on' => $code->expires_on->format('d-m-Y'),
        ], link: '/admin/my-discount-codes');
    }

    private function loaded(DiscountCode $code): DiscountCode
    {
        return $code->load(['coursePackages', 'students'])->loadCount(['appliedRedemptions', 'students']);
    }
}
