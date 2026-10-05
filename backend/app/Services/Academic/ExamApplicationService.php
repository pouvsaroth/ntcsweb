<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\Enrollment;
use App\Models\ExamApplication;
use App\Models\Product;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\PaymentService;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Billing\PaymentMethod;
use App\Support\Billing\ProductType;
use App\Support\Notifications\NotificationType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A student's own exam application — see ExamApplication's own docblock.
 * Exam-day logistics stay a manual, offline school process. Applying never
 * charges anything — but approving does: every way an application becomes
 * approved records its fee as a paid Cash Invoice + Payment (see
 * recordFeeOnApproval()), the same records the admin-driven "Print" action
 * makes (see sellAndRecordFee()), so the fee shows up in Billing and
 * Accounting like every other payment.
 */
final class ExamApplicationService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
        private readonly NotificationService $notifications,
        private readonly ApprovalFlow $flow,
    ) {}

    /**
     * The student self-service "Apply" flow. The student never *picks*
     * exam-day logistics (book/room/table/date) themselves — that stays a
     * teacher/admin decision, confirmed or corrected via the Examination
     * tab (see createForAdmin()/updateForAdmin()) — but this still seeds
     * them with the same enrollment-derived defaults createForAdmin() uses,
     * rather than leaving them blank. What this does:
     *
     *   1. Saves the student's own personal-info edits back to their real
     *      Student record — same fields the admin's Application Form edits,
     *      just self-service (no `students.update` permission needed).
     *   2. Either creates a fresh application (status pending) for this
     *      enrollment, defaulting classroom/table/book the same way
     *      createForAdmin() does, or — if a teacher already sent this
     *      enrollment to exam (see the migration's docblock on "at most one
     *      application per enrollment, ever") — leaves that existing row's
     *      logistics untouched (it already has its own defaults, or a
     *      teacher's deliberate override) and simply stamps the fee
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

        $application = DB::transaction(function () use ($student, $enrollmentId, $studentFields, $photo, $tenant) {
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

            // Same enrollment-derived defaults createForAdmin() applies —
            // without them, a student applying with no teacher-created draft
            // ahead of them would land in the Approval queue with an empty
            // room/table/book that a reviewer has to look up and fill in by
            // hand before they can even consider approving it.
            $enrollment = Enrollment::query()->with(['schoolClass', 'coursePackage.books'])->findOrFail($enrollmentId);

            return ExamApplication::query()->create([
                'student_id' => $student->id,
                'enrollment_id' => $enrollmentId,
                'classroom_id' => $enrollment->schoolClass?->classroom_id,
                'table_id' => $enrollment->table_id,
                'book_id' => $enrollment->coursePackage?->books->first()?->id,
                'fee_amount' => $tenant->exam_fee_amount,
                'fee_currency' => $tenant->default_currency,
                'student_marked_paid_at' => now(),
                'status' => ExamApplication::STATUS_PENDING,
            ]);
        });

        // Outside the transaction — a notification that fails to write is
        // never worth rolling back an already-submitted application over.
        $this->notifyApprovers($application, $this->flow->submitRecipients(
            $application,
            fn () => $this->notifications->usersWithPermission(Permissions::EXAM_APPLICATIONS_APPROVE),
        ));

        return $application;
    }

    /**
     * "Waiting for your approval" — on submit, and again for each next
     * step's group of an approval flow (see ApprovalFlow::approve()).
     *
     * @param  BaseCollection<int, User>  $recipients
     */
    public function notifyApprovers(ExamApplication $application, BaseCollection $recipients): void
    {
        $this->notifications->notifyMany(
            $recipients,
            NotificationType::EXAM_APPLICATION_SUBMITTED,
            ['student_id' => $application->student_id, 'student_name' => $application->student?->fullName(), 'exam_application_id' => $application->id],
            link: '/admin/approvals/queue',
        );
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
        $application = DB::transaction(function () use ($application, $admin) {
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

            $this->recordFeeOnApproval($application, $admin);

            return $application->fresh();
        });

        $this->notifyStudent($application, NotificationType::EXAM_APPLICATION_APPROVED);

        return $application;
    }

    public function reject(ExamApplication $application, string $reason, User $admin): ExamApplication
    {
        $application = DB::transaction(function () use ($application, $reason, $admin) {
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

        $this->notifyStudent($application, NotificationType::EXAM_APPLICATION_REJECTED, ['reason' => $reason]);

        return $application;
    }

    /** @param  array<string, mixed>  $extra */
    private function notifyStudent(ExamApplication $application, string $type, array $extra = []): void
    {
        $studentUser = $application->student?->user;

        if ($studentUser === null) {
            return;
        }

        $this->notifications->notifyMany(collect([$studentUser]), $type, [
            'student_id' => $application->student_id,
            'student_name' => $application->student?->fullName(),
            'exam_application_id' => $application->id,
            ...$extra,
        ], link: '/admin/my-exam-applications');
    }

    /**
     * "Not Exam" — the Examination tab's per-row action for a student who
     * was sent to exam (still DRAFT) but doesn't want to sit it, or who has
     * a still-unscored MAKE_UP retake they'd rather give up on ("លះបង់ការប្រឡង")
     * than sit again. Only those two qualify: once a student has actually
     * applied (pending/approved/rejected), this isn't the right action any
     * more — reject() covers "applied, then turned down". Kept, not
     * deleted, so there's a record they were offered the exam and
     * declined; the enrollment moves straight to completed since they're
     * done with the course either way.
     */
    public function markNotExam(ExamApplication $application): ExamApplication
    {
        return DB::transaction(function () use ($application) {
            /** @var ExamApplication $application */
            $application = ExamApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($application->status, [ExamApplication::STATUS_DRAFT, ExamApplication::STATUS_MAKE_UP], true)) {
                throw ValidationException::withMessages(['status' => 'Only a not-yet-applied or make-up exam application can be marked Not Exam.']);
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

        // Eager-loaded so ExamApplicationResource's whenLoaded('book'/'classroom'/'table')
        // actually serializes them — without this they silently come back
        // missing from the JSON (not null, just absent), which the
        // Application Form reads as "nothing set" and shows every
        // Examination Information field blank even when the application
        // already has a book/room/table assigned.
        $enrollment->setRelation(
            'latestExamApplication',
            ExamApplication::query()->where('enrollment_id', $enrollment->id)->with(['book', 'classroom', 'table'])->latest('id')->first(),
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
    public function createForAdmin(array $data, ?User $actor = null): ExamApplication
    {
        $enrollment = Enrollment::query()->with(['schoolClass', 'coursePackage.books'])->findOrFail($data['enrollment_id']);

        $data['classroom_id'] ??= $enrollment->schoolClass?->classroom_id;
        $data['table_id'] ??= $enrollment->table_id;
        $data['book_id'] ??= $enrollment->coursePackage?->books->first()?->id;

        return DB::transaction(function () use ($data, $enrollment, $actor) {
            $application = ExamApplication::query()->create([
                ...$data,
                'student_id' => $enrollment->student_id,
            ]);

            if ($actor !== null && $application->status === ExamApplication::STATUS_APPROVED) {
                $this->recordFeeOnApproval($application, $actor);
            }

            return $application->fresh();
        });
    }

    /**
     * @param  array{book_id?:int|null, file_code?:string|null, exam_date?:string|null, exam_time?:string|null, exam_time_out?:string|null, table_no?:string|null, classroom_id?:int|null, table_id?:int|null, status:string, remark?:string|null}  $data
     */
    public function updateForAdmin(ExamApplication $application, array $data, ?User $actor = null): ExamApplication
    {
        return DB::transaction(function () use ($application, $data, $actor) {
            $wasApproved = $application->status === ExamApplication::STATUS_APPROVED;
            $application->update($data);

            if ($actor !== null && ! $wasApproved && $application->status === ExamApplication::STATUS_APPROVED) {
                $this->recordFeeOnApproval($application, $actor);
            }

            return $application->fresh();
        });
    }

    /**
     * Every way an application becomes approved records its exam fee as a
     * paid Cash invoice + payment (approval date), the same records "Print"
     * makes — see sellAndRecordFee(). The amount is the fee saved on the
     * application when the student applied, else the school's current exam
     * fee. Skipped when the fee was already taken (`sold_at`), or when no fee
     * is set anywhere — the approval itself still goes through.
     */
    private function recordFeeOnApproval(ExamApplication $application, User $actor): void
    {
        if ($application->sold_at !== null) {
            return;
        }

        $tenant = $this->context->getOrFail();
        $fee = $application->fee_amount ?? $tenant->exam_fee_amount;

        if ($fee === null || (float) $fee <= 0) {
            return;
        }

        $this->sellAndRecordFee(
            $application,
            (float) $fee,
            $application->fee_currency ?? $tenant->default_currency,
            PaymentMethod::CASH,
            now()->toDateString(),
            $actor,
        );
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
     * Examination → Certificate's "Received photo": the student brought in
     * their certificate photo. Only for passed applications (see
     * ExamApplication::scopePassed()); saving again overwrites the date and
     * remark. One update per row, so each gets its own audit entry.
     *
     * @param  list<int>  $ids
     * @return Collection<int, ExamApplication>
     */
    public function markPhotoReceived(array $ids, string $receivedDate, ?string $remark, User $actor): Collection
    {
        $applications = ExamApplication::query()->whereIn('id', $ids)->get();
        $passedIds = ExamApplication::query()->whereIn('id', $ids)->passed()->pluck('id')->all();

        if (count($passedIds) !== $applications->count()) {
            throw ValidationException::withMessages(['ids' => 'Only students who passed the exam can have a certificate photo received.']);
        }

        DB::transaction(function () use ($applications, $receivedDate, $remark, $actor) {
            foreach ($applications as $application) {
                $application->update([
                    'photo_received_date' => $receivedDate,
                    'photo_received_remark' => $remark,
                    'photo_received_by' => $actor->getKey(),
                ]);
            }
        });

        return $applications->each->refresh();
    }

    /**
     * Examination → Certificate's "Issued": the certificate was handed to
     * the student. Same rules as markPhotoReceived() — passed applications
     * only, saving again overwrites, one audited update per row.
     *
     * @param  list<int>  $ids
     * @return Collection<int, ExamApplication>
     */
    public function markCertificateIssued(array $ids, string $issuedDate, ?string $remark, User $actor): Collection
    {
        $applications = ExamApplication::query()->whereIn('id', $ids)->get();
        $passedIds = ExamApplication::query()->whereIn('id', $ids)->passed()->pluck('id')->all();

        if (count($passedIds) !== $applications->count()) {
            throw ValidationException::withMessages(['ids' => 'Only students who passed the exam can be issued a certificate.']);
        }

        DB::transaction(function () use ($applications, $issuedDate, $remark, $actor) {
            foreach ($applications as $application) {
                $application->update([
                    'certificate_issued_date' => $issuedDate,
                    'certificate_issued_remark' => $remark,
                    'certificate_issued_by' => $actor->getKey(),
                ]);
            }
        });

        return $applications->each->refresh();
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
