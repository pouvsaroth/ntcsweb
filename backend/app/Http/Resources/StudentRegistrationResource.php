<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The admin "Student Registration Pending" queue's row/detail shape — a
 * trimmed view of Student plus the enrollment and invoice an admin actually
 * needs to decide whether to approve. See StudentRegistrationService.
 *
 * @mixin Student
 */
class StudentRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $enrollment = $this->enrollments->first();
        $invoice = $this->invoices->sortByDesc('id')->first();

        return [
            'id' => $this->id,
            'student_code' => $this->student_code,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'photo_url' => $this->photoUrl(),
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'phone' => $this->phone,
            'email' => $this->email,
            'house_no' => $this->house_no,
            'street_no' => $this->street_no,
            'village_code' => $this->village_code,
            'other_address' => $this->other_address,
            'status' => $this->status,
            'enrollment' => $enrollment === null ? null : [
                'id' => $enrollment->id,
                'class' => $enrollment->schoolClass === null ? null : ['id' => $enrollment->schoolClass->id, 'name' => $enrollment->schoolClass->name],
                'course_package' => $enrollment->coursePackage === null ? null : ['id' => $enrollment->coursePackage->id, 'name' => $enrollment->coursePackage->name],
                'academic_program' => $enrollment->academicProgram === null ? null : ['id' => $enrollment->academicProgram->id, 'name' => $enrollment->academicProgram->name],
                'fee' => (float) $enrollment->fee,
                'fee_type' => $enrollment->fee_type,
            ],
            'invoice' => $invoice === null ? null : [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'total' => (float) $invoice->total,
                'balance' => (float) $invoice->balance,
                'currency' => $invoice->currency,
                'intended_payment_method' => $invoice->intended_payment_method,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
