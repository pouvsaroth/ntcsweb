<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\CoursePackage;
use App\Models\DiscountCode;
use App\Models\DiscountCodeRedemption;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Academic\EnrollmentService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Using a discount code (Invoices > Discount codes): check it for a student,
 * course and fee, work out the money off, record the use, and free the use
 * again when its invoice or enrollment is cancelled.
 *
 * A code works when it is active and today is within its dates (no expiry =
 * no end). A main code works for every student, except a student who has
 * their own codes: they use only those. A student's own code
 * (STUDENTCODE_CODE_ddmmyy, made by assigning a main code) works only for
 * them. A code can be used on every enrollment. A percent code takes that
 * share of the fee (up to its cap); an amount code takes its amount. Never
 * more than the fee.
 */
final class DiscountCodeService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  string  $field  the request field errors are reported against
     * @return array{code: DiscountCode, amount: float}
     */
    public function quote(string $code, ?int $studentId, float $fee, string $currency, bool $lock = false, string $field = 'discount_code'): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages([$field => $message]);

        /** @var DiscountCode|null $discount */
        $discount = DiscountCode::query()
            ->where('code', strtoupper(trim($code)))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();

        if ($discount === null) {
            $fail(__('This discount code does not exist.'));
        }

        $today = $this->today();

        if ($discount->status !== DiscountCode::STATUS_ACTIVE) {
            $fail(__('This discount code is not active.'));
        }
        if ($discount->starts_on->toDateString() > $today) {
            $fail(__('This discount code can be used from :date.', ['date' => $discount->starts_on->format('d-m-Y')]));
        }
        if ($discount->expires_on !== null && $discount->expires_on->toDateString() < $today) {
            $fail(__('This discount code expired on :date.', ['date' => $discount->expires_on->format('d-m-Y')]));
        }

        if ($discount->audience === DiscountCode::AUDIENCE_PERSONAL
            && ($studentId === null || ! $discount->students()->where('students.id', $studentId)->exists())) {
            $fail(__('This discount code is not for this student.'));
        }

        // A student with their own codes uses only those.
        if ($discount->audience === DiscountCode::AUDIENCE_PUBLIC && $studentId !== null && $this->ownCodes($studentId)->exists()) {
            $fail(__('This student has their own discount code. Use one of those.'));
        }

        // A student may use the same code on every enrollment (no once-per-student rule).

        if ($discount->discount_type === DiscountCode::TYPE_AMOUNT && $discount->currency !== $currency) {
            $fail(__('This discount code is in :currency and cannot be used on this invoice.', ['currency' => $discount->currency]));
        }

        return ['code' => $discount, 'amount' => $this->amountFor($discount, $fee, $currency)];
    }

    /** What the students' own codes copy from their main code (and follow when it is edited). */
    public const SHARED = ['name', 'discount_type', 'value', 'max_discount', 'currency', 'starts_on', 'status', 'notes'];

    /**
     * The forms' dropdown: the student's own codes that work today; when they
     * have none, every main code that works today. The amount is still
     * worked out by the check when one is picked.
     *
     * @return Collection<int, DiscountCode>
     */
    public function availableFor(?int $studentId): Collection
    {
        if ($studentId !== null) {
            $own = $this->ownCodes($studentId)->orderBy('code')->get();
            if ($own->isNotEmpty()) {
                return $own;
            }
        }

        return $this->usable(DiscountCode::query()->where('audience', DiscountCode::AUDIENCE_PUBLIC))->orderBy('code')->get();
    }

    /**
     * The student's own codes that work today.
     *
     * @return Builder<DiscountCode>
     */
    public function ownCodes(int $studentId): Builder
    {
        return $this->usable(DiscountCode::query()
            ->where('audience', DiscountCode::AUDIENCE_PERSONAL)
            ->whereHas('students', fn ($s) => $s->where('students.id', $studentId)));
    }

    /**
     * Active, started, and not expired (no expiry = no end).
     *
     * @param  Builder<DiscountCode>  $query
     * @return Builder<DiscountCode>
     */
    public function usable(Builder $query): Builder
    {
        $today = $this->today();

        return $query
            ->where('status', DiscountCode::STATUS_ACTIVE)
            ->whereDate('starts_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $today));
    }

    /**
     * Assign discount to student: each student gets their own code made
     * from the main code, STUDENTCODE_CODE_ddmmyy. A student who already has
     * one from this main code keeps it (nothing new is made).
     *
     * Each student's code gets its own expiry ($expiries[student id]; a
     * student not in the map gets the main code's expiry; null = no end).
     * It never follows later changes to the main code's expiry.
     *
     * @param  list<int>  $studentIds
     * @param  array<int, string|null>  $expiries
     * @return list<DiscountCode> the codes just made
     */
    public function assign(DiscountCode $main, array $studentIds, ?User $actor, array $expiries = []): array
    {
        if ($main->parent_id !== null) {
            throw ValidationException::withMessages(['discount_code_id' => __('Pick a main discount code, not a student code.')]);
        }

        return DB::connection('tenant')->transaction(function () use ($main, $studentIds, $actor, $expiries) {
            $has = DB::connection('tenant')->table('discount_code_student')
                ->join('discount_codes', 'discount_codes.id', '=', 'discount_code_student.discount_code_id')
                ->where('discount_codes.parent_id', $main->id)
                ->pluck('discount_code_student.student_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $date = Carbon::parse($this->today());
            $made = [];

            foreach (Student::query()->whereIn('id', array_diff($studentIds, $has))->orderBy('id')->get() as $student) {
                $code = DiscountCode::query()->create([
                    ...$main->only(self::SHARED),
                    'expires_on' => array_key_exists($student->id, $expiries) ? $expiries[$student->id] : $main->expires_on?->toDateString(),
                    'parent_id' => $main->id,
                    'code' => $this->ownCodeFor($main, $student, $date),
                    'audience' => DiscountCode::AUDIENCE_PERSONAL,
                    'created_by' => $actor?->id,
                ]);
                $code->students()->attach($student->id);
                $made[] = $code;
            }

            return $made;
        });
    }

    /** The main code was just saved: its students' own codes follow what changed. */
    public function followMain(DiscountCode $main): void
    {
        $changed = array_intersect_key($main->getChanges(), array_flip(self::SHARED));
        if ($changed !== [] && $main->parent_id === null) {
            $main->children()->get()->each(fn (DiscountCode $code) => $code->update($changed));
        }
    }

    /** STUDENTCODE_CODE_ddmmyy, e.g. NTS000543_D01_101126 (-2, -3 ... if taken). */
    public function ownCodeFor(DiscountCode $main, Student $student, Carbon $date): string
    {
        $tail = strtoupper((string) preg_replace('/[^A-Za-z0-9-]/', '', (string) $student->student_code)) ?: 'S'.$student->id;
        $base = $tail.'_'.$main->code.'_'.$date->format('dmy');

        $code = $base;
        for ($n = 2; DiscountCode::query()->where('code', $code)->exists(); $n++) {
            $code = $base.'-'.$n;
        }

        return $code;
    }

    /**
     * The forms' live check before saving: what the code takes off this
     * course's fee for this student (null = a new student, e.g. the website).
     * `$fees` is EnrollmentService (passed in: it already depends on this class).
     *
     * @return array{code:string, name:string, discount_type:string, value:float, amount:float, fee:float, total:float, currency:string}
     */
    public function checkForEnrollment(string $code, ?int $studentId, CoursePackage $package, string $feeType, EnrollmentService $fees): array
    {
        if ($package->{'fee_'.$feeType} === null) {
            throw ValidationException::withMessages(['fee_type' => __('This package does not offer the selected fee type.')]);
        }

        ['fee' => $fee, 'currency' => $currency] = $fees->invoiceFee($package, $feeType, $this->today());
        ['code' => $discount, 'amount' => $amount] = $this->quote($code, $studentId, $fee, $currency);

        return [
            'code' => $discount->code,
            'name' => $discount->name,
            'discount_type' => $discount->discount_type,
            'value' => (float) $discount->value,
            'amount' => $amount,
            'fee' => $fee,
            'total' => max($fee - $amount, 0),
            'currency' => $currency,
        ];
    }

    /**
     * The course an invoice is for: its enrollment line (an enrollment or
     * monthly invoice). Null = not a course invoice, so no code applies.
     */
    public function enrollmentLine(Invoice $invoice): ?InvoiceItem
    {
        return $invoice->items()->where('reference_type', Enrollment::class)->first();
    }

    /** A student may put a code on their own invoice while nothing is paid and it has no discount yet. */
    public function canTakeCode(Invoice $invoice): bool
    {
        return ! $invoice->isClosed()
            && (float) $invoice->paid_amount <= 0
            && (float) $invoice->discount <= 0
            && $this->enrollmentLine($invoice) !== null;
    }

    /**
     * Student portal: the student puts a code on one of their own unpaid
     * invoices. The discount comes off the course line's amount.
     */
    public function applyToInvoice(Invoice $invoice, string $code, User $actor): Invoice
    {
        return DB::connection('tenant')->transaction(function () use ($invoice, $code, $actor) {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->canTakeCode($invoice)) {
                throw ValidationException::withMessages([
                    'discount_code' => __('A discount code can only be used on an unpaid course invoice that has no discount yet.'),
                ]);
            }

            $line = $this->enrollmentLine($invoice);
            $enrollment = Enrollment::query()->find($line->reference_id);
            if ($enrollment === null || $enrollment->course_package_id === null) {
                throw ValidationException::withMessages(['discount_code' => __('This invoice is not for a course.')]);
            }

            ['code' => $discount, 'amount' => $amount] = $this->quote(
                $code, (int) $invoice->student_id, (float) $line->total, $invoice->currency, lock: true,
            );

            $invoice->update(['discount' => $amount, 'discount_reason' => $this->reasonFor($discount)]);
            $invoice = app(InvoiceService::class)->recalculate($invoice);
            $this->redeem($discount, (int) $invoice->student_id, $invoice, (int) $enrollment->getKey(), $amount, $actor);

            return $invoice;
        });
    }

    public function amountFor(DiscountCode $discount, float $fee, string $currency): float
    {
        if ($discount->discount_type === DiscountCode::TYPE_PERCENT) {
            $amount = $fee * (float) $discount->value / 100;
            if ($discount->max_discount !== null && $discount->currency === $currency) {
                $amount = min($amount, (float) $discount->max_discount);
            }
        } else {
            $amount = (float) $discount->value;
        }

        $amount = min($amount, $fee);

        return $currency === Tenant::CURRENCY_KHR ? round($amount) : round($amount, 2);
    }

    /** "Code NEWYEAR10" — what the invoice's discount reason says. */
    public function reasonFor(DiscountCode $discount): string
    {
        return 'Code '.$discount->code;
    }

    public function redeem(DiscountCode $discount, int $studentId, Invoice $invoice, ?int $enrollmentId, float $amount, ?User $actor): DiscountCodeRedemption
    {
        return DiscountCodeRedemption::query()->create([
            'discount_code_id' => $discount->id,
            'student_id' => $studentId,
            'invoice_id' => $invoice->id,
            'enrollment_id' => $enrollmentId,
            'amount' => $amount,
            'currency' => $invoice->currency,
            'used_by' => $actor?->id,
        ]);
    }

    /** The invoice was cancelled or voided: the student may use the code again. */
    public function releaseForInvoice(int $invoiceId): void
    {
        DiscountCodeRedemption::query()
            ->where('invoice_id', $invoiceId)
            ->where('status', DiscountCodeRedemption::STATUS_APPLIED)
            ->update(['status' => DiscountCodeRedemption::STATUS_RELEASED]);
    }

    /** The enrollment was dropped (or its registration rejected). */
    public function releaseForEnrollment(int $enrollmentId): void
    {
        DiscountCodeRedemption::query()
            ->where('enrollment_id', $enrollmentId)
            ->where('status', DiscountCodeRedemption::STATUS_APPLIED)
            ->update(['status' => DiscountCodeRedemption::STATUS_RELEASED]);
    }

    /** Today in the school's own timezone. */
    public function today(): string
    {
        return Carbon::now($this->context->get()?->timezone ?: 'Asia/Phnom_Penh')->toDateString();
    }
}
