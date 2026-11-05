<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\ApprovalStepApproval;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\KhmerFont;
use App\Services\Pdf\PdfImageEncoder;
use App\Support\Approvals\DocumentType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;

/**
 * HRM > Payroll > Payslip > Export PDF: many payslips of one payroll in one
 * A4 PDF, one per page. The signature boxes follow the payroll's approval:
 * "Prepared by" is who sent it for approval, then one box per approved step
 * of the Payroll approval flow (or one "Approved by" when the school has no
 * flow), each with that person's saved staff signature. "Received by" stays
 * blank for the staff member to sign by hand.
 */
final class PayslipPdfService
{
    private const SUPPORTED_LOCALES = ['en', 'km'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly BrowsershotRenderer $renderer,
        private readonly ApprovalFlow $flow,
    ) {}

    public static function resolveLocale(?string $raw): ?string
    {
        return in_array($raw, self::SUPPORTED_LOCALES, true) ? $raw : null;
    }

    /** @param Collection<int, Payslip> $slips */
    public function render(PayrollRun $run, Collection $slips, ?string $locale = null): string
    {
        $tenant = $this->context->getOrFail();
        $previous = App::getLocale();
        // The admin's own app language when en/km, else the school's (School Settings).
        App::setLocale($locale ?? (self::resolveLocale($tenant->locale) ?? 'en'));

        try {
            $html = view('pdf.payslips', [
                'run' => $run,
                'slips' => $slips,
                'signers' => $this->signers($run),
                'tenant' => $tenant,
                'logoDataUri' => PdfImageEncoder::dataUri($tenant->logoPath()),
                'khmerFontRegular' => KhmerFont::dataUri('Regular'),
                'khmerFontBold' => KhmerFont::dataUri('Bold'),
            ])->render();
        } finally {
            App::setLocale($previous);
        }

        return $this->renderer->render($html, 'A4', marginMm: 12);
    }

    public function filename(PayrollRun $run): string
    {
        return 'payslips-'.preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $run->reference).'.pdf';
    }

    /**
     * The signature boxes above "Received by", in order.
     *
     * @return list<array{title: string, name: string|null, position: string|null, signature: string|null, date: string|null}>
     */
    public function signers(PayrollRun $run): array
    {
        $run->loadMissing(['requestedBy.staff.position', 'decidedBy.staff.position']);
        $boxes = [$this->box(__('payslip.prepared_by'), $run->requestedBy, $run->submitted_at)];

        $approved = in_array($run->status, [PayrollRun::STATUS_APPROVED, PayrollRun::STATUS_PAID], true);
        $steps = $this->flow->steps(DocumentType::PAYROLL_RUN);

        if ($steps->isEmpty()) {
            $boxes[] = $this->box(__('payslip.approved_by'), $approved ? $run->decidedBy : null, $approved ? $run->decided_at : null);

            return $boxes;
        }

        // Approvals of this sending only: one rejected earlier may still hold older ones.
        $approvals = ApprovalStepApproval::query()
            ->where('approvable_type', DocumentType::PAYROLL_RUN)
            ->where('approvable_id', $run->getKey())
            ->when($run->submitted_at !== null, fn ($q) => $q->where('approved_at', '>=', $run->submitted_at))
            ->get()
            ->keyBy('step_order');
        $users = User::query()->with('staff.position')->whereIn('id', $approvals->pluck('user_id'))->get()->keyBy('id');

        foreach ($steps as $step) {
            $approval = $approvals->get($step->step_order);
            $boxes[] = $this->box(
                $step->group?->name ?? __('payslip.approved_by'),
                $approval !== null ? $users->get($approval->user_id) : null,
                $approval?->approved_at,
            );
        }

        return $boxes;
    }

    /** @return array{title: string, name: string|null, position: string|null, signature: string|null, date: string|null} */
    private function box(string $title, ?User $user, ?Carbon $at): array
    {
        $staff = $user?->staff;

        return [
            'title' => $title,
            'name' => $staff?->fullName() ?? $user?->name,
            'position' => $staff?->position?->name,
            'signature' => PdfImageEncoder::dataUri($staff?->signaturePath()),
            'date' => $user !== null ? $at?->format('d-m-Y') : null,
        ];
    }

    /** A payslip line's name in the PDF's language — same rules as the app's payslipLineLabel(). */
    public static function lineLabel(array $line): string
    {
        $type = $line['type'] ?? null;
        $code = $line['code'] ?? null;
        $name = (string) ($line['name'] ?? '');

        return match ($type) {
            'basic' => $code === 'shift' ? __('payslip.lines.basic').': '.$name : __('payslip.lines.basic'),
            'overtime' => __('payslip.lines.overtime'),
            'social_security' => __('payslip.lines.nssf'),
            'tax' => __('payslip.lines.tax'),
            'attendance', 'loan' => in_array($code, ['absence', 'unpaid_leave', 'late', 'early_leave', 'loan', 'advance'], true) ? __('payslip.lines.'.$code) : $name,
            'adjustment' => $name === 'adjustment' ? __('payslip.lines.adjustment') : __('payslip.lines.adjustment').': '.$name,
            default => $name,
        };
    }

    public static function money(float $amount, ?string $currency): string
    {
        return match ($currency) {
            'KHR' => number_format(round($amount)).' ៛',
            'USD' => '$'.number_format($amount, 2),
            default => number_format($amount, 2),
        };
    }
}
