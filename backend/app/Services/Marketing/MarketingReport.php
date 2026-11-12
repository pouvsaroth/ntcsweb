<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\Inquiry;
use App\Models\MarketingCampaign;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Marketing > Report: inquiries (leads) dated in a period, how many became
 * students, by source and by campaign, with each campaign's real cost and
 * the fees those students have paid since they asked (per currency, never
 * added together).
 */
final class MarketingReport
{
    /** @return array<string, mixed> */
    public function build(string $from, string $to): array
    {
        $inquiries = Inquiry::query()
            ->whereBetween('inquiry_date', [$from, $to])
            ->get(['id', 'inquiry_date', 'source', 'status', 'student_id', 'marketing_campaign_id']);

        $fees = $this->fees($inquiries);

        $bySource = [];
        foreach (Inquiry::SOURCES as $source) {
            $rows = $inquiries->where('source', $source);
            if ($rows->isEmpty()) {
                continue;
            }
            $bySource[] = ['source' => $source, ...$this->counts($rows)];
        }

        $campaigns = MarketingCampaign::query()
            ->whereIn('id', $inquiries->pluck('marketing_campaign_id')->filter()->unique()->values())
            ->get(['id', 'code', 'name', 'channel', 'currency', 'spent'])
            ->keyBy('id');

        $byCampaign = [];
        foreach ($inquiries->groupBy(fn (Inquiry $i) => (int) $i->marketing_campaign_id) as $campaignId => $rows) {
            $campaign = $campaigns->get($campaignId);
            $counts = $this->counts($rows);
            $spent = $campaign?->spent !== null ? (float) $campaign->spent : null;
            $byCampaign[] = [
                'id' => $campaign?->id,
                'code' => $campaign?->code,
                'name' => $campaign?->name,
                'channel' => $campaign?->channel,
                'currency' => $campaign?->currency,
                'spent' => $spent,
                ...$counts,
                'cost_per_lead' => $spent !== null && $counts['leads'] > 0 ? round($spent / $counts['leads'], 2) : null,
                'cost_per_student' => $spent !== null && $counts['enrolled'] > 0 ? round($spent / $counts['enrolled'], 2) : null,
                'fees' => $fees['by_campaign'][$campaignId] ?? [],
            ];
        }
        // Campaigns first, most leads first; "No campaign" last.
        usort($byCampaign, fn ($a, $b) => [$a['id'] === null, -$a['leads']] <=> [$b['id'] === null, -$b['leads']]);

        return [
            'from' => $from,
            'to' => $to,
            'totals' => [...$this->counts($inquiries), 'fees' => $fees['total']],
            'by_source' => $bySource,
            'by_campaign' => $byCampaign,
        ];
    }

    /** Leads in a period, how many enrolled, and the best source (the dashboard card). */
    public function summary(string $from, string $to): array
    {
        $inquiries = Inquiry::query()->whereBetween('inquiry_date', [$from, $to])->get(['id', 'source', 'status', 'student_id']);
        $top = $inquiries->groupBy('source')->map->count()->sortDesc();

        return [
            'from' => $from,
            'to' => $to,
            ...$this->counts($inquiries),
            'top_source' => $top->keys()->first(),
            'top_source_leads' => (int) ($top->first() ?? 0),
        ];
    }

    /**
     * @param  Collection<int, Inquiry>  $rows
     * @return array{leads: int, enrolled: int, not_interested: int, open: int, conversion: float}
     */
    private function counts(Collection $rows): array
    {
        $leads = $rows->count();
        $enrolled = $rows->filter(fn (Inquiry $i) => $i->status === 'enrolled' || $i->student_id !== null)->count();

        return [
            'leads' => $leads,
            'enrolled' => $enrolled,
            'not_interested' => $rows->where('status', 'not_interested')->whereNull('student_id')->count(),
            'open' => $rows->whereIn('status', Inquiry::OPEN_STATUSES)->whereNull('student_id')->count(),
            'conversion' => $leads > 0 ? round($enrolled / $leads * 100, 1) : 0.0,
        ];
    }

    /**
     * Completed payments by the students these inquiries became, paid on or
     * after the inquiry date; a student counted once per campaign.
     *
     * @param  Collection<int, Inquiry>  $inquiries
     * @return array{total: list<array{currency: string, amount: float}>, by_campaign: array<int, list<array{currency: string, amount: float}>>}
     */
    private function fees(Collection $inquiries): array
    {
        // Earliest inquiry per (campaign, student).
        $pairs = [];
        foreach ($inquiries->whereNotNull('student_id') as $inquiry) {
            $key = (int) $inquiry->marketing_campaign_id.':'.$inquiry->student_id;
            $date = $inquiry->inquiry_date->toDateString();
            if (! isset($pairs[$key]) || $date < $pairs[$key]['date']) {
                $pairs[$key] = ['campaign' => (int) $inquiry->marketing_campaign_id, 'student' => (int) $inquiry->student_id, 'date' => $date];
            }
        }
        if ($pairs === []) {
            return ['total' => [], 'by_campaign' => []];
        }

        $payments = Payment::query()->completed()
            ->whereIn('student_id', array_unique(array_column($pairs, 'student')))
            ->get(['student_id', 'amount', 'currency', 'payment_date'])
            ->groupBy('student_id');

        $byCampaign = [];
        $firstDate = [];
        foreach ($pairs as $pair) {
            foreach ($payments->get($pair['student'], collect()) as $payment) {
                if ($payment->payment_date !== null && $payment->payment_date->toDateString() < $pair['date']) {
                    continue;
                }
                $byCampaign[$pair['campaign']][$payment->currency] = ($byCampaign[$pair['campaign']][$payment->currency] ?? 0) + (float) $payment->amount;
            }
            $firstDate[$pair['student']] = min($firstDate[$pair['student']] ?? $pair['date'], $pair['date']);
        }

        // The total counts each student once, from their earliest inquiry.
        $total = [];
        foreach ($firstDate as $student => $date) {
            foreach ($payments->get($student, collect()) as $payment) {
                if ($payment->payment_date !== null && $payment->payment_date->toDateString() < $date) {
                    continue;
                }
                $total[$payment->currency] = ($total[$payment->currency] ?? 0) + (float) $payment->amount;
            }
        }

        $list = fn (array $sums) => array_map(fn ($c) => ['currency' => $c, 'amount' => round($sums[$c], 2)], array_values(array_intersect(['USD', 'KHR'], array_keys($sums))));

        return [
            'total' => $list($total),
            'by_campaign' => array_map($list, $byCampaign),
        ];
    }
}
