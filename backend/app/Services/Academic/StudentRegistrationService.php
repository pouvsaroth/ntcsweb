<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\Invoice;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\PaymentService;
use App\Support\Billing\PaymentMethod;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The public self-registration path: a visitor becomes a User (pending
 * approval, with the password they chose themselves — unlike
 * UserProvisioningService::provision()'s admin-created accounts, which
 * always start at the phone number) + a pending Student + a real
 * Enrollment/Invoice via the existing, unmodified EnrollmentService — same
 * fee computation and invoicing an admin-entered package enrollment gets.
 *
 * Nothing here is billed yet: the registrant intends to pay cash, in
 * person or later, so no Payment is recorded at registration time.
 * approve() is the one place that both records the payment and flips the
 * account live, mirroring how a front-desk cash enrollment normally works
 * — just deferred until the school confirms the money actually arrived.
 */
final class StudentRegistrationService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentIdGenerator $studentIdGenerator,
        private readonly EnrollmentService $enrollments,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @param  array{first_name:string, last_name:string, gender?:string|null, date_of_birth?:string|null, phone:string, email?:string|null, house_no?:string|null, street_no?:string|null, village_code?:string|null, other_address?:string|null, photo_path?:string|null, password:string, class_id:int, course_package_id:int, fee_type:string, payment_method:string}  $data
     */
    public function register(array $data): Student
    {
        return DB::transaction(function () use ($data) {
            $tenantId = $this->context->idOrFail();

            $studentRole = Role::query()
                ->where('tenant_id', $tenantId)
                ->where('slug', Role::STUDENT)
                ->firstOrFail();

            $user = new User([
                'name' => trim("{$data['first_name']} {$data['last_name']}"),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'password' => Hash::make($data['password']),
                'status' => User::STATUS_PENDING_APPROVAL,
            ]);
            $user->forceFill(['tenant_id' => $tenantId]);
            $user->save();
            $user->attachRoles($studentRole);

            $student = Student::query()->create([
                'user_id' => $user->id,
                'student_code' => $this->studentIdGenerator->next($this->context->getOrFail()),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'gender' => $data['gender'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'house_no' => $data['house_no'] ?? null,
                'street_no' => $data['street_no'] ?? null,
                'village_code' => $data['village_code'] ?? null,
                'other_address' => $data['other_address'] ?? null,
                'photo_path' => $data['photo_path'] ?? null,
                'enrollment_date' => now()->toDateString(),
                'status' => Student::STATUS_PENDING,
            ]);

            // The registrant is their own audit actor here — there is no
            // admin performing this action, and EnrollmentService requires
            // one for its audit log entry.
            $this->enrollments->enrollInPackage([
                'student_id' => $student->id,
                'class_id' => $data['class_id'],
                'course_package_id' => $data['course_package_id'],
                'fee_type' => $data['fee_type'],
            ], $user);

            // EnrollmentService (shared with the admin package-enrollment
            // flow, left completely untouched here) has no concept of an
            // "intended" payment method — it only ever records a real
            // Payment. This is a separate, direct update purely so approve()
            // below knows which method to actually record once confirmed.
            $this->pendingInvoice($student)?->update(['intended_payment_method' => $data['payment_method']]);

            return $student->fresh();
        });
    }

    public function approve(Student $student, User $admin): Student
    {
        return DB::transaction(function () use ($student, $admin) {
            /** @var Student $student */
            $student = Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

            if ($student->status !== Student::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This registration is not pending approval.']);
            }

            $invoice = $this->pendingInvoice($student);

            if ($invoice !== null && (float) $invoice->balance > 0) {
                $this->payments->record($invoice, [
                    'amount' => (float) $invoice->balance,
                    'payment_method' => $invoice->intended_payment_method ?? PaymentMethod::CASH,
                ], $admin);
            }

            $student->update(['status' => Student::STATUS_ACTIVE]);
            $student->user?->update(['status' => User::STATUS_ACTIVE]);

            return $student->fresh();
        });
    }

    public function reject(Student $student, string $reason, User $admin): Student
    {
        return DB::transaction(function () use ($student, $reason) {
            /** @var Student $student */
            $student = Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

            if ($student->status !== Student::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This registration is not pending approval.']);
            }

            $student->auditReason = $reason;
            $student->update(['status' => Student::STATUS_INACTIVE]);
            $student->user?->update(['status' => User::STATUS_SUSPENDED]);

            return $student->fresh();
        });
    }

    private function pendingInvoice(Student $student): ?Invoice
    {
        return Invoice::query()
            ->where('student_id', $student->id)
            ->latest('id')
            ->first();
    }
}
