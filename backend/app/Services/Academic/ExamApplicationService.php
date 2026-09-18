<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\Enrollment;
use App\Models\ExamApplication;
use App\Models\Product;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\PaymentService;
use App\Support\Billing\ProductType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A student's own exam application — see ExamApplication's own docblock.
 * Unlike LeaveRequestService::approve(), approve()/reject() here have no
 * side effect beyond the row itself: exam-day logistics stay a manual,
 * offline school process in v1. The self-service fee is never charged
 * automatically (see the migration's docblock: "show the fee, student
 * self-declares paid, staff verifies in Billing") — but the admin-driven
 * "Print" action (see sellAndRecordFee()) does record a real Invoice +
 * Payment, because that's staff actually taking the fee at the counter.
 */
final class ExamApplicationService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
    ) {}

    /**
     * The student self-service "Apply" flow. Unlike the old submit(), the
     * student never sets exam-day logistics (book/room/table/date) — that
     * stays exclusively a teacher/admin action via the Examination tab (see
     * createForAdmin()/updateForAdmin()). What this does:
     *
     *   1. Saves the student's own personal-info edits back to their real
     *      Student record — same fields the admin's Application Form edits,
     *      just self-service (no `students.update` permission needed).
     *   2. Either creates a fresh, blank-logistics application (status
     *      pending) for this enrollment, or — if a teacher already sent
     *      this enrollment to exam (see the migration's docblock on "at
     *      most one application per enrollment, ever") — leaves that
     *      existing row's logistics untouched and simply stamps the fee
     *      snapshot + payment declaration onto it, since the student is
     *      only now getting around to confirming/paying.
     *
     * @param  array{first_name:string, last_name:string, english_name:?string, gender:?string, date_of_birth:?string, phone:?string, village_code:?string}  $studentFields
     */
    public function applyOnline(Student $student, int $enrollmentId, array $studentFields, ?UploadedFile $photo): ExamApplication
    {
        $tenant = $this->context->getOrFail();

        if ($tenant->exam_fee_amount === null) {
            throw ValidationException::withMessages([
                'exam_fee_amount' => 'The exam fee has not been configured yet. Please contact your school.',
            ]);
        }

        return DB::transaction(function () use ($student, $enrollmentId, $studentFields, $photo, $tenant) {
            $previousPhotoPath = $student->photo_path;
            $newPhotoPath = $photo !== null ? $this->storeStudentPhoto($photo, $tenant) : null;

            $student->update([
                ...$studentFields,
                ...($newPhotoPath !== null ? ['photo_path' => $newPhotoPath] : []),
            ]);

            if ($newPhotoPath !== null && $previousPhotoPath !== null) {
                Storage::disk('public')->delete($previousPhotoPath);
            }

            $existing = ExamApplication::query()->where('enrollment_id', $enrollmentId)->first();

            if ($existing !== null) {
                // Whatever it was (draft, from "Send to Exam", or already
                // pending from an earlier submission), applying always
                // lands it on pending — this *is* the "student actually
                // applied" moment the Approval tab is waiting for.
                $existing->update([
                    'fee_amount' => $tenant->exam_fee_amount,
                    'fee_currency' => $tenant->default_currency,
                    'student_marked_paid_at' => now(),
                    'status' => ExamApplication::STATUS_PENDING,
                ]);

                return $existing->fresh();
            }

            return ExamApplication::query()->create([
                'student_id' => $student->id,
                'enrollment_id' => $enrollmentId,
                'fee_amount' => $tenant->exam_fee_amount,
                'fee_currency' => $tenant->default_currency,
                'student_marked_paid_at' => now(),
                'status' => ExamApplication::STATUS_PENDING,
            ]);
        });
    }

    private function storeStudentPhoto(UploadedFile $photo, Tenant $tenant): string
    {
        $path = $photo->store($tenant->storagePath('students'), 'public');

        if ($path === false) {
            abort(500, 'Failed to store the uploaded photo.');
        }

        return $path;
    }

    /**
     * The student self-service equivalent of lookupByEnrollmentCode() — no
     * code to type, since the student picks from their own enrollments (see
     * MyExamApplicationController::enrollments()). Ownership is the caller's
     * job to check ($enrollment->student_id === $student->id) before this
     * is reached.
     */
    public function lookupForStudent(int $enrollmentId): Enrollment
    {
        $enrollment = Enrollment::query()
            ->with(['student', 'coursePackage'])
            ->findOrFail($enrollmentId);

        $enrollment->setRelation(
            'latestExamApplication',
            ExamApplication::query()->where('enrollment_id', $enrollment->id)->with(['book', 'classroom', 'table'])->latest('id')->first(),
        );

        return $enrollment;
    }

    public function approve(ExamApplication $application, User $admin): ExamApplication
    {
        return DB::transaction(function () use ($application, $admin) {
            /** @var ExamApplication $application */
            $application = ExamApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if ($application->status !== ExamApplication::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This exam application has already been decided.']);
            }

            $application->update([
                'status' => ExamApplication::STATUS_APPROVED,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $application->fresh();
        });
    }

    public function reject(ExamApplication $application, string $reason, User $admin): ExamApplication
    {
        return DB::transaction(function () use ($application, $reason, $admin) {
            /** @var ExamApplication $application */
            $application = ExamApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if ($application->status !== ExamApplication::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This exam application has already been decided.']);
            }

            $application->update([
                'status' => ExamApplication::STATUS_REJECTED,
                'decision_reason' => $reason,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $application->fresh();
        });
    }

    /**
     * "Not Exam" — the Examination tab's per-row action for a student who
     * was sent to exam (still DRAFT) but doesn't want to sit it. Only a
     * still-draft row qualifies: once a student has actually applied
     * (pending/approved/rejected), this isn't the right action any more —
     * reject() covers "applied, then turned down". Kept, not deleted, so
     * there's a record they were offered the exam and declined; the
     * enrollment moves straight to completed since they're done with the
     * course either way.
     */
    public function markNotExam(ExamApplication $application): ExamApplication
    {
        return DB::transaction(function () use ($application) {
            /** @var ExamApplication $application */
            $application = ExamApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if ($application->status !== ExamApplication::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Only a not-yet-applied exam application can be marked Not Exam.']);
            }

            $application->update(['status' => ExamApplication::STATUS_NOT_EXAM]);
            $application->enrollment()->update(['status' => Enrollment::STATUS_COMPLETED]);

            return $application->fresh();
        });
    }

    /**
     * Resolves the "Enrollment Code" lookup on the Application Form —
     * `$code` is an {@see Enrollment::$enrollments_code}, not a separate
     * exam-specific id: this system already has a stable, human-readable
     * per-enrollment code in exactly that shape, so nothing new was
     * invented here. Returns the enrollment (with student + course
     * package) plus its most recent exam application, if any — the form
     * edits that one if found, or starts a blank one against this
     * enrollment otherwise.
     */
    public function lookupByEnrollmentCode(string $code): Enrollment
    {
        $enrollment = Enrollment::query()
            ->where('enrollments_code', $code)
            ->with(['student', 'coursePackage'])
            ->first();

        if ($enrollment === null) {
            throw ValidationException::withMessages(['enrollment_code' => 'No enrollment found for this Enrollment Code.']);
        }

        $enrollment->setRelation(
            'latestExamApplication',
            ExamApplication::query()->where('enrollment_id', $enrollment->id)->latest('id')->first(),
        );

        return $enrollment;
    }

    /**
     * @param  array{enrollment_id:int, book_id?:int|null, file_code?:string|null, exam_date?:string|null, exam_time?:string|null, exam_time_out?:string|null, table_no?:string|null, classroom_id?:int|null, table_id?:int|null, status:string, remark?:string|null}  $data
     */
    /**
     * Defaults `classroom_id`/`table_id`/`book_id` from the enrollment
     * itself whenever the caller didn't pick them explicitly (they arrive
     * here as `null` either way — StoreExamApplicationRequest's fields are
     * all `nullable`, never `sometimes`) — the roster's bulk "Send to Exam"
     * (ClassStudents.vue) never sends them at all, so without this every
     * bulk-created application would sit with an empty room/table/book
     * until someone filled it in by hand. The room/table are simply the
     * student's own class/seat; the book is the first (by sort_order) in
     * their course package — a package with more than one book still needs
     * a human to confirm which one, but this default is right the vast
     * majority of the time and is always editable afterward.
     */
    public function createForAdmin(array $data): ExamApplication
    {
        $enrollment = Enrollment::query()->with(['schoolClass', 'coursePackage.books'])->findOrFail($data['enrollment_id']);

        $data['classroom_id'] ??= $enrollment->schoolClass?->classroom_id;
        $data['table_id'] ??= $enrollment->table_id;
        $data['book_id'] ??= $enrollment->coursePackage?->books->first()?->id;

        return ExamApplication::query()->create([
            ...$data,
            'student_id' => $enrollment->student_id,
        ]);
    }

    /**
     * @param  array{book_id?:int|null, file_code?:string|null, exam_date?:string|null, exam_time?:string|null, exam_time_out?:string|null, table_no?:string|null, classroom_id?:int|null, table_id?:int|null, status:string, remark?:string|null}  $data
     */
    public function updateForAdmin(ExamApplication $application, array $data): ExamApplication
    {
        $application->update($data);

        return $application->fresh();
    }

    /**
     * "Print" on the Exam Application grid: takes the fee at the counter for
     * one application, records it as a real Invoice + Payment (so it shows
     * up in Billing/Accounting like every other payment), and stamps
     * `sold_at` — the print itself (the Application Form document) happens
     * client-side afterward, using this response.
     */
    public function sellAndRecordFee(ExamApplication $application, float $fee, string $currency, string $paymentMethod, string $printDate, User $actor): ExamApplication
    {
        // Plain DB::transaction(), matching InvoiceService::create() and
        // PaymentService::record() below — both already assume the default
        // connection is the tenant one mid-request (see approve()/reject()
        // above for the same convention in this class), so nesting under
        // DB::connection('tenant') here would just start a second,
        // non-atomic transaction on top of theirs.
        return DB::transaction(function () use ($application, $fee, $currency, $paymentMethod, $printDate, $actor) {
            /** @var ExamApplication $application */
            $application = ExamApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            $application->loadMissing('student');

            $product = $this->examFeeProduct();

            $invoice = $this->invoices->create([
                'student_id' => $application->student_id,
                'invoice_date' => $printDate,
                'currency' => $currency,
                'items' => [[
                    'product_id' => $product->id,
                    'unit_price' => $fee,
                    'description' => "Exam fee: {$application->student?->fullName()}",
                    'reference_type' => ExamApplication::class,
                    'reference_id' => $application->id,
                ]],
            ], $actor);

            $this->payments->record($invoice, [
                'amount' => $fee,
                'payment_method' => $paymentMethod,
                'payment_date' => $printDate,
            ], $actor);

            $application->update([
                'sold_at' => now(),
                'fee_amount' => $fee,
                'fee_currency' => $currency,
            ]);

            return $application->fresh();
        });
    }

    /**
     * One "Exam Fee" catalog Product per tenant, auto-provisioned on first
     * use — a school shouldn't have to go set up a Product before staff can
     * take an exam fee, the same reasoning CoursePackage's own Product
     * creation follows.
     */
    private function examFeeProduct(): Product
    {
        return Product::query()->firstOrCreate(
            ['code' => 'EXAM-FEE'],
            ['name' => 'Exam Fee', 'type' => ProductType::EXAM_FEE],
        );
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, ExamApplication>
     */
    public function markReceived(array $ids): Collection
    {
        return $this->stamp($ids, 'received_at');
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, ExamApplication>
     */
    public function markPaidBack(array $ids): Collection
    {
        return $this->stamp($ids, 'paid_back_at');
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, ExamApplication>
     */
    private function stamp(array $ids, string $column): Collection
    {
        $applications = ExamApplication::query()->whereIn('id', $ids)->get();
        ExamApplication::query()->whereIn('id', $ids)->update([$column => now()]);

        return $applications->each->refresh();
    }
}
