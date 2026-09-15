<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The shared "which enrollments are billed monthly, and when is each one's
 * next payment due" query — backs the admin Monthly Invoice tab
 * (MonthlyInvoiceController), the dashboard alert widget, and each student's
 * own payment-due popup (MyMonthlyPaymentAlertController). One place for the
 * date math so all three can never drift apart.
 *
 * `next_payment_date` isn't a stored column anywhere: it's derived as
 * enrolled_at + N months, where N is how many monthly invoices have been
 * issued for that enrollment so far (the initial enrollment invoice counts
 * as month 1, so a freshly-enrolled student's next payment is due exactly
 * one month after they started). Recording a new monthly invoice next month
 * bumps N and pushes this forward automatically — nothing to reconcile.
 */
final class MonthlyBillingScheduleService
{
    /**
     * @return Builder<Enrollment>
     */
    public function query(): Builder
    {
        return Enrollment::query()
            ->whereHas('invoiceItems.invoice', fn (Builder $q) => $q->where('payment_type', 'monthly'))
            ->with([
                'student', 'schoolClass', 'coursePackage',
                // Newest first, so the latest one is simply `->first()` —
                // backs the "reprint invoice" action on the Monthly Invoice
                // tab (see MonthlyInvoiceResource::toArray()).
                'invoiceItems' => function ($query) {
                    $query->whereHas('invoice', fn (Builder $q) => $q->where('payment_type', 'monthly'))
                        ->with('invoice')
                        ->latest('id');
                },
            ])
            ->withCount(['invoiceItems as monthly_invoices_count' => function (Builder $q) {
                $q->whereHas('invoice', fn (Builder $q2) => $q2->where('payment_type', 'monthly'));
            }]);
    }

    /**
     * Sets the `next_payment_date` attribute on each enrollment in place —
     * every caller must have eager-loaded `monthly_invoices_count` via
     * query() above first.
     *
     * @param  iterable<Enrollment>  $enrollments
     */
    public function annotate(iterable $enrollments): void
    {
        foreach ($enrollments as $enrollment) {
            $enrollment->setAttribute('next_payment_date', $this->nextPaymentDate($enrollment));
        }
    }

    public function nextPaymentDate(Enrollment $enrollment): ?string
    {
        return $enrollment->enrolled_at?->copy()
            ->addMonths(max(1, (int) $enrollment->monthly_invoices_count))
            ->toDateString();
    }

    /**
     * Every monthly-billed, currently-active enrollment whose next payment
     * is already due or falls within `$withinDays` — the set the dashboard
     * widget and the student popup both alert on.
     *
     * @return Collection<int, Enrollment>
     */
    public function due(int $withinDays): Collection
    {
        $threshold = now()->addDays($withinDays)->toDateString();

        $enrollments = $this->query()->active()->get();
        $this->annotate($enrollments);

        return $enrollments
            ->filter(fn (Enrollment $enrollment) => $enrollment->next_payment_date !== null && $enrollment->next_payment_date <= $threshold)
            ->sortBy('next_payment_date')
            ->values();
    }
}
