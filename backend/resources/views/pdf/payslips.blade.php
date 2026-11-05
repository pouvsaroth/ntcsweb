@php
    use App\Services\Payroll\PayslipPdfService as Pdf;
    $period = ($run->period_start?->format('d-m-Y') ?? '').' – '.($run->period_end?->format('d-m-Y') ?? '');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<style>
    {{-- Noto Sans Khmer as data URIs, same reason as invoice.blade.php. --}}
    @font-face { font-family: 'Noto Sans Khmer'; font-weight: normal; src: url('{{ $khmerFontRegular }}'); }
    @font-face { font-family: 'Noto Sans Khmer'; font-weight: bold; src: url('{{ $khmerFontBold }}'); }
    body { margin: 0; font-family: 'Noto Sans Khmer', DejaVu Sans, sans-serif; font-size: 11px; color: #111827; line-height: 1.45; }
    .slip { page-break-after: always; }
    .slip:last-child { page-break-after: auto; }
    .head { display: table; width: 100%; border-bottom: 2px solid #1f2937; padding-bottom: 8px; margin-bottom: 12px; }
    .head > div { display: table-cell; vertical-align: middle; }
    .head .logo { width: 60px; }
    .head .logo img { width: 52px; height: 52px; object-fit: contain; }
    .head h1 { font-size: 15px; margin: 0; }
    .head p { margin: 0; color: #4b5563; font-size: 10px; }
    .head .title { text-align: right; }
    .head h2 { font-size: 16px; margin: 0; letter-spacing: 0.04em; }
    table { border-collapse: collapse; width: 100%; }
    .info th { text-align: left; color: #4b5563; font-weight: normal; padding: 3px 6px; width: 15%; }
    .info td { font-weight: bold; padding: 3px 6px; }
    .cols { display: table; width: 100%; margin-top: 12px; border-spacing: 0; }
    .cols > div { display: table-cell; width: 50%; vertical-align: top; }
    .cols > div:first-child { padding-right: 8px; }
    .cols > div:last-child { padding-left: 8px; }
    .lines th, .lines td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
    .lines thead th { background: #f3f4f6; }
    .lines tfoot td { font-weight: bold; border-top: 2px solid #9ca3af; }
    .num { text-align: right !important; white-space: nowrap; }
    .net { font-size: 14px; text-align: right; margin: 12px 0 2px; }
    .employer { color: #4b5563; font-size: 10px; text-align: right; margin: 0; }
    .signs { display: table; width: 100%; table-layout: fixed; margin-top: 28px; }
    .sign { display: table-cell; text-align: center; vertical-align: top; padding: 0 6px; }
    .sign .who { font-size: 10px; color: #4b5563; height: 16px; margin-bottom: 4px; }
    .sign .img { height: 56px; }
    .sign .img img { max-height: 56px; max-width: 100%; }
    .sign .line { border-top: 1px solid #111827; padding-top: 4px; font-weight: bold; min-height: 14px; }
    .sign .sub { font-size: 9px; color: #6b7280; min-height: 12px; }
</style>
</head>
<body>
@foreach ($slips as $slip)
    @php
        $staff = $slip->staff;
        $lines = $slip->lines ?? [];
        $earnings = $lines['earnings'] ?? [];
        $deductions = $lines['deductions'] ?? [];
        $employer = $lines['employer'] ?? [];
        $sum = fn (array $rows) => array_sum(array_map(fn ($l) => (float) ($l['amount'] ?? 0), $rows));
        $bank = $slip->payment_method === 'bank'
            ? implode(' · ', array_filter([$slip->bank_name, $slip->bank_account_number, $slip->bank_account_name]))
            : __('payslip.cash');
        $bank = $bank !== '' ? $bank : '—';
    @endphp
    <section class="slip">
        <div class="head">
            @if ($logoDataUri)<div class="logo"><img src="{{ $logoDataUri }}" alt=""></div>@endif
            <div>
                <h1>{{ $tenant->name }}</h1>
                <p>{{ implode(' · ', array_filter([$tenant->address, $tenant->phone])) }}</p>
            </div>
            <div class="title">
                <h2>{{ __('payslip.title') }}</h2>
                <p>{{ $period }}</p>
            </div>
        </div>

        <table class="info">
            <tr>
                <th>{{ __('payslip.staff') }}</th><td>{{ $staff?->fullName() }} ({{ $staff?->employee_code }})</td>
                <th>{{ __('payslip.reference') }}</th><td>{{ $run->reference }}</td>
            </tr>
            <tr>
                <th>{{ __('payslip.position') }}</th><td>{{ implode(' · ', array_filter([$staff?->position?->name, $staff?->department?->name])) }}</td>
                <th>{{ __('payslip.pay_date') }}</th><td>{{ $run->pay_date?->format('d-m-Y') }}</td>
            </tr>
            <tr><th>{{ __('payslip.payment') }}</th><td colspan="3">{{ $bank }}</td></tr>
        </table>

        <div class="cols">
            <div>
                <table class="lines">
                    <thead><tr><th>{{ __('payslip.earnings') }}</th><th class="num">{{ __('payslip.amount') }}</th></tr></thead>
                    <tbody>
                        @foreach ($earnings as $line)
                            <tr><td>{{ Pdf::lineLabel($line) }}</td><td class="num">{{ Pdf::money((float) $line['amount'], $slip->currency) }}</td></tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><td>{{ __('payslip.gross') }}</td><td class="num">{{ Pdf::money($sum($earnings), $slip->currency) }}</td></tr></tfoot>
                </table>
            </div>
            <div>
                <table class="lines">
                    <thead><tr><th>{{ __('payslip.deductions') }}</th><th class="num">{{ __('payslip.amount') }}</th></tr></thead>
                    <tbody>
                        @forelse ($deductions as $line)
                            <tr><td>{{ Pdf::lineLabel($line) }}</td><td class="num">{{ Pdf::money((float) $line['amount'], $slip->currency) }}</td></tr>
                        @empty
                            <tr><td colspan="2">—</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot><tr><td>{{ __('payslip.total_deductions') }}</td><td class="num">{{ Pdf::money($sum($deductions), $slip->currency) }}</td></tr></tfoot>
                </table>
            </div>
        </div>

        <p class="net">{{ __('payslip.net') }}: <strong>{{ Pdf::money((float) $slip->net_pay, $slip->currency) }}</strong></p>
        @if (count($employer))
            <p class="employer">{{ __('payslip.employer_paid') }}:
                {{ implode(' · ', array_map(fn ($l) => Pdf::lineLabel($l).' '.Pdf::money((float) $l['amount'], $slip->currency), $employer)) }}</p>
        @endif

        <div class="signs">
            @foreach ($signers as $signer)
                <div class="sign">
                    <div class="who">{{ $signer['title'] }}</div>
                    <div class="img">@if ($signer['signature'])<img src="{{ $signer['signature'] }}" alt="">@endif</div>
                    <div class="line">{{ $signer['name'] }}</div>
                    <div class="sub">{{ implode(' · ', array_filter([$signer['position'], $signer['date']])) }}</div>
                </div>
            @endforeach
            <div class="sign">
                <div class="who">{{ __('payslip.received_by') }}</div>
                <div class="img"></div>
                <div class="line">{{ $staff?->fullName() }}</div>
                <div class="sub">{{ __('payslip.date') }}: ____/____/______</div>
            </div>
        </div>
    </section>
@endforeach
</body>
</html>
