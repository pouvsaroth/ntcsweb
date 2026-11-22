<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Enrollment;
use App\Models\InvoiceItem;
use Illuminate\Support\Collection;

/**
 * Payment type (monthly, term, video, ...) of enrollments:
 * 1. the payment type on its own invoice;
 * 2. else, the one on the student's previous dropped enrollment — before
 *    2026-09-23 a class/course change closed the old enrollment and opened a
 *    new one without an invoice, so the invoice stayed on the old row;
 * 3. else, the one on an invoice left behind by a deleted enrollment of the
 *    same student (enrollments used to be deletable even when paid);
 * 4. else null (unknown).
 * Used by Term study alerts and by video access (Student::videoCourseIds()).
 */
final class EnrollmentPaymentTypes
{
    /**
     * @param  Collection<int, Enrollment>  $enrollments  with invoiceItems.invoice loaded
     * @return array<int, string|null> payment type per enrollment id
     */
    public static function resolve(Collection $enrollments): array
    {
        $types = [];
        $missing = [];

        foreach ($enrollments as $enrollment) {
            $types[$enrollment->id] = self::ownType($enrollment);
            if ($types[$enrollment->id] === null) {
                $missing[] = $enrollment;
            }
        }

        if ($missing === []) {
            return $types;
        }

        // The students' earlier dropped enrollments, newest first.
        $previous = Enrollment::query()
            ->whereIn('student_id', array_map(fn (Enrollment $e) => $e->student_id, $missing))
            ->where('status', Enrollment::STATUS_DROPPED)
            ->with('invoiceItems.invoice')
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_id');

        foreach ($missing as $enrollment) {
            foreach ($previous->get($enrollment->student_id, collect()) as $earlier) {
                if ($earlier->id >= $enrollment->id) {
                    continue;
                }
                $type = self::ownType($earlier);
                if ($type !== null) {
                    $types[$enrollment->id] = $type;
                    break;
                }
            }
        }

        // Invoices whose enrollment was deleted: newest payment type per student.
        $stillMissing = array_filter($missing, fn (Enrollment $e) => $types[$e->id] === null);
        if ($stillMissing !== []) {
            $orphaned = InvoiceItem::query()
                ->where('reference_type', Enrollment::class)
                ->whereNotIn('reference_id', Enrollment::query()->select('id'))
                ->whereHas('invoice', fn ($q) => $q
                    ->whereIn('student_id', array_map(fn (Enrollment $e) => $e->student_id, $stillMissing))
                    ->whereNotNull('payment_type'))
                ->with('invoice')
                ->orderByDesc('id')
                ->get()
                ->groupBy(fn (InvoiceItem $item) => $item->invoice->student_id);

            foreach ($stillMissing as $enrollment) {
                $types[$enrollment->id] = $orphaned->get($enrollment->student_id)?->first()?->invoice?->payment_type;
            }
        }

        return $types;
    }

    /** The newest payment type on the enrollment's own invoices, if any. */
    private static function ownType(Enrollment $enrollment): ?string
    {
        return $enrollment->invoiceItems
            ->sortByDesc('id')
            ->map(fn (InvoiceItem $item) => $item->invoice?->payment_type)
            ->first(fn (?string $type) => $type !== null);
    }
}
