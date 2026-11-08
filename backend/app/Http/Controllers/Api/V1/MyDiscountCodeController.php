<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Responses\ApiResponse;
use App\Models\DiscountCode;
use App\Models\DiscountCodeRedemption;
use App\Models\Invoice;
use App\Models\Student;
use App\Services\Billing\DiscountCodeService;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Student portal > My discount codes: the codes a student can see (their
 * personal codes, public codes that work today, and any code they used),
 * plus their unpaid course invoices a code can still go on. Identity-gated
 * like MyInvoiceController — a student only ever reaches their own rows.
 */
final class MyDiscountCodeController extends Controller
{
    public function __construct(private readonly DiscountCodeService $discounts) {}

    public function index(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);
        $today = $this->discounts->today();

        $used = DiscountCodeRedemption::query()
            ->where('student_id', $student->id)
            ->where('status', DiscountCodeRedemption::STATUS_APPLIED)
            ->get()
            ->keyBy('discount_code_id');

        $codes = DiscountCode::query()
            ->with('coursePackages')
            ->withCount('appliedRedemptions')
            ->where(fn (Builder $q) => $q
                // Given to this student (any state, so they see expired ones too).
                ->whereHas('students', fn (Builder $s) => $s->where('students.id', $student->id))
                // Public codes that work today.
                ->orWhere(fn (Builder $p) => $p
                    ->where('audience', DiscountCode::AUDIENCE_PUBLIC)
                    ->where('status', DiscountCode::STATUS_ACTIVE)
                    ->whereDate('starts_on', '<=', $today)
                    ->whereDate('expires_on', '>=', $today))
                // Or one they already used.
                ->orWhereIn('id', $used->keys()))
            ->orderByDesc('expires_on')
            ->get()
            ->map(function (DiscountCode $code) use ($used, $today) {
                $redemption = $used->get($code->id);
                $status = $code->effectiveStatus((int) $code->applied_redemptions_count, $today);

                return [
                    'id' => $code->id,
                    'code' => $code->code,
                    'name' => $code->name,
                    'audience' => $code->audience,
                    'discount_type' => $code->discount_type,
                    'value' => (float) $code->value,
                    'max_discount' => $code->max_discount !== null ? (float) $code->max_discount : null,
                    'currency' => $code->currency,
                    'starts_on' => $code->starts_on->toDateString(),
                    'expires_on' => $code->expires_on->toDateString(),
                    'course_packages' => $code->coursePackages->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
                    // Used by this student = used, whatever the code's own state.
                    'state' => $redemption !== null ? 'used' : $status,
                    'used_at' => $redemption?->created_at?->toIso8601String(),
                    'used_amount' => $redemption !== null ? (float) $redemption->amount : null,
                ];
            })
            // Usable ones first, then the rest.
            ->sortBy(fn (array $row) => $row['state'] === 'active' ? 0 : 1)
            ->values();

        $invoices = Invoice::query()
            ->where('student_id', $student->id)
            ->whereNotIn('status', [InvoiceStatus::CANCELLED, InvoiceStatus::VOID, InvoiceStatus::STOPPED])
            ->where('paid_amount', '<=', 0)
            ->where('discount', '<=', 0)
            ->with('items')
            ->latest('id')
            ->get()
            ->filter(fn (Invoice $invoice) => $this->discounts->canTakeCode($invoice))
            ->values();

        return ApiResponse::success([
            'codes' => $codes,
            'invoices' => InvoiceResource::collection($invoices),
        ]);
    }

    /** POST /my-invoices/{invoice}/discount-code — put a code on one of my unpaid invoices. */
    public function apply(Request $request, Invoice $invoice): JsonResponse
    {
        $student = $this->studentOrFail($request);
        abort_unless($invoice->student_id === $student->id, 404);

        $data = $request->validate(['discount_code' => ['required', 'string', 'max:40']]);

        $invoice = $this->discounts->applyToInvoice($invoice, $data['discount_code'], $request->user());

        return ApiResponse::success(new InvoiceResource($invoice->load('items')));
    }

    private function studentOrFail(Request $request): Student
    {
        $student = $request->user()?->student;

        if ($student === null) {
            throw ValidationException::withMessages([
                'student' => 'This account is not linked to a student record.',
            ]);
        }

        return $student;
    }
}
