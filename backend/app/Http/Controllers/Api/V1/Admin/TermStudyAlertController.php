<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * The dashboard's "term students studying too long" list: every Studying
 * enrollment billed by term (payment_type term or term_online, or no payment
 * type recorded at all — see index()) whose enroll
 * date is more than the school's School Settings → "term alert after" days
 * ago (Tenant::termStudyAlertDays(), default 31). Days are counted from
 * enrolled_at to today.
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

        $rows = Enrollment::query()
            ->active()
            // Term, or payment type not recorded (enrollments brought over
            // from the old system have no invoice, or an imported one with no
            // payment type). Only enrollments clearly billed monthly or by
            // video are left out.
            ->where(fn (Builder $q) => $q
                ->whereHas('invoiceItems.invoice', fn (Builder $i) => $i->whereIn('payment_type', self::TERM_PAYMENT_TYPES))
                ->orWhereDoesntHave('invoiceItems.invoice', fn (Builder $i) => $i->whereNotNull('payment_type')))
            ->withExists(['invoiceItems as term_type_recorded' => fn (Builder $q) => $q
                ->whereHas('invoice', fn (Builder $i) => $i->whereIn('payment_type', self::TERM_PAYMENT_TYPES))])
            ->whereDate('enrolled_at', '<', $today->copy()->subDays($days)->toDateString())
            ->with(['student', 'schoolClass', 'coursePackage'])
            ->orderBy('enrolled_at')
            ->get()
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
                'type_recorded' => (bool) $e->term_type_recorded,
            ]);

        return ApiResponse::success(['days' => $days, 'items' => $rows]);
    }
}
