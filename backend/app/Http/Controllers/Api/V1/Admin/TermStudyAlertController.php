<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Support\Billing\EnrollmentPaymentTypes;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * The dashboard's "term students studying too long" list: every Studying
 * course enrollment billed by term whose enroll date is more than the
 * school's School Settings → "term alert after" days ago
 * (Tenant::termStudyAlertDays(), default 31). Days are counted from
 * enrolled_at to today.
 *
 * Payment type of an enrollment (see EnrollmentPaymentTypes):
 * 1. the payment type on its own invoice;
 * 2. else, the one on the student's previous dropped enrollment — before
 *    2026-09-23 a class/course change closed the old enrollment and opened a
 *    new one without an invoice, so the invoice stayed on the old row;
 * 3. else, the one on an invoice left behind by a deleted enrollment of the
 *    same student (enrollments used to be deletable even when paid);
 * 4. else unknown — still listed (counted as term, the owner's choice),
 *    with `type_recorded` false so the dashboard can tag it.
 * Enrollments known to be monthly or video are left out, and so are company
 * classes (Billing > Companies): the company pays the class once, so its
 * students have no invoice and no term to pay.
 */
final class TermStudyAlertController extends Controller
{
    public const TERM_PAYMENT_TYPES = ['term', 'term_online'];

    public function __construct(private readonly TenantContext $context) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        /** @var Tenant $tenant */
        $tenant = $this->context->getOrFail();
        $days = $tenant->termStudyAlertDays();
        $today = now()->startOfDay();

        $enrollments = Enrollment::query()
            ->active()
            ->whereNotNull('course_package_id')
            ->where(fn ($q) => $q->whereNull('class_id')->orWhereHas('schoolClass', fn ($c) => $c->whereNull('company_id')))
            ->whereDate('enrolled_at', '<', $today->copy()->subDays($days)->toDateString())
            ->with(['student', 'schoolClass', 'coursePackage', 'invoiceItems.invoice'])
            ->orderBy('enrolled_at')
            ->get();

        $types = EnrollmentPaymentTypes::resolve($enrollments);

        $rows = $enrollments
            ->filter(fn (Enrollment $e) => $types[$e->id] === null || in_array($types[$e->id], self::TERM_PAYMENT_TYPES, true))
            ->values()
            ->map(fn (Enrollment $e) => [
                'id' => $e->id,
                'student' => $e->student ? [
                    'id' => $e->student->id,
                    'student_code' => $e->student->student_code,
                    'name' => $e->student->fullName(),
                ] : null,
                'course' => $e->coursePackage?->name,
                'class' => $e->schoolClass?->name,
                'enrolled_at' => $e->enrolled_at?->toDateString(),
                'days' => $e->enrolled_at ? (int) $e->enrolled_at->copy()->startOfDay()->diffInDays($today) : null,
                // False when listed only because no payment type is recorded.
                'type_recorded' => $types[$e->id] !== null,
            ]);

        return ApiResponse::success(['days' => $days, 'items' => $rows]);
    }
}
