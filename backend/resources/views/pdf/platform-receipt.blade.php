<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- Bundled Khmer font, as data URIs — see pdf/receipt.blade.php. --}}
    @font-face { font-family: 'Noto Sans Khmer'; font-style: normal; font-weight: normal; src: url('{{ $khmerFontRegular }}'); }
    @font-face { font-family: 'Noto Sans Khmer'; font-style: normal; font-weight: bold; src: url('{{ $khmerFontBold }}'); }
    body { font-family: 'Noto Sans Khmer', DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; margin: 0; position: relative; }
    .head { display: table; width: 100%; border-bottom: 2px solid #b45309; padding-bottom: 10px; }
    .head .logo { display: table-cell; width: 70px; vertical-align: middle; }
    .head .logo img { max-height: 56px; max-width: 64px; }
    .head .company { display: table-cell; vertical-align: middle; }
    .head .company h1 { font-size: 15px; margin: 0 0 2px; }
    .head .company p { margin: 0; color: #4b5563; }
    .head .title { display: table-cell; text-align: right; vertical-align: middle; }
    .head .title h2 { font-size: 18px; margin: 0; letter-spacing: 2px; color: #b45309; }
    .head .title p { margin: 2px 0 0; font-weight: bold; }
    .box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px 14px; margin-top: 14px; }
    .row { display: table; width: 100%; padding: 3px 0; }
    .row .label { display: table-cell; width: 40%; color: #6b7280; }
    .row .value { display: table-cell; width: 60%; text-align: right; font-weight: bold; }
    .amount { text-align: center; font-size: 26px; font-weight: bold; margin: 16px 0 4px; color: #b45309; }
    .muted { color: #6b7280; text-align: center; margin: 0; }
    .foot { margin-top: 28px; display: table; width: 100%; }
    .foot .cell { display: table-cell; width: 50%; text-align: center; vertical-align: bottom; }
    .foot .line { border-top: 1px solid #9ca3af; width: 70%; margin: 36px auto 4px; }
    .void { position: fixed; top: 38%; left: 0; right: 0; text-align: center; font-size: 90px; font-weight: bold; color: rgba(220, 38, 38, 0.18); transform: rotate(-24deg); letter-spacing: 12px; }
    .void-reason { color: #b91c1c; text-align: center; margin-top: 8px; font-weight: bold; }
</style>
</head>
<body>
    @php
        $money = fn ($amount) => $payment->currency === 'KHR'
            ? number_format(round((float) $amount)).' ៛'
            : '$'.number_format((float) $amount, 2);
        $date = fn ($d) => $d?->format('d-m-Y');
        $methods = ['cash' => 'Cash', 'bank' => 'Bank transfer', 'khqr' => 'KHQR', 'cheque' => 'Cheque', 'other' => 'Other'];
    @endphp

    @if($payment->isVoid())<div class="void">VOID</div>@endif

    <div class="head">
        @if($logoDataUri)<div class="logo"><img src="{{ $logoDataUri }}" alt=""></div>@endif
        <div class="company">
            <h1>{{ $settings->company_name ?: config('app.name') }}</h1>
            @if($settings->company_address)<p>{{ $settings->company_address }}</p>@endif
            @if($settings->company_phone || $settings->company_email)
                <p>{{ collect([$settings->company_phone, $settings->company_email])->filter()->implode(' · ') }}</p>
            @endif
            @if($settings->company_tax_number)<p>VATTIN: {{ $settings->company_tax_number }}</p>@endif
        </div>
        <div class="title">
            <h2>RECEIPT</h2>
            <p>{{ $payment->receipt_number }}</p>
            <p style="font-weight: normal; color: #4b5563;">{{ $date($payment->paid_on) }}</p>
        </div>
    </div>

    <div class="box">
        <div class="row"><div class="label">Received from</div><div class="value">{{ $school?->name }}@if($school?->name_en) ({{ $school->name_en }})@endif</div></div>
        <div class="row"><div class="label">Plan</div><div class="value">{{ $plan?->name }}</div></div>
        @if($payment->covered_from && $payment->covered_to)
            <div class="row"><div class="label">Period covered</div><div class="value">{{ $date($payment->covered_from) }} → {{ $date($payment->covered_to) }}</div></div>
        @endif
        <div class="row"><div class="label">Periods paid</div><div class="value">{{ $payment->periods }}</div></div>
        <div class="row"><div class="label">Payment method</div><div class="value">{{ $methods[$payment->method] ?? $payment->method }}</div></div>
        @if($payment->reference)
            <div class="row"><div class="label">Reference</div><div class="value">{{ $payment->reference }}</div></div>
        @endif
    </div>

    <div class="amount">{{ $money($payment->amount) }}</div>
    <p class="muted">Amount received</p>
    @if($payment->notes)<p class="muted" style="margin-top: 8px;">{{ $payment->notes }}</p>@endif
    @if($payment->isVoid())
        <p class="void-reason">Voided {{ $payment->voided_at?->format('d-m-Y') }}: {{ $payment->void_reason }}</p>
    @endif

    <div class="foot">
        <div class="cell"><div class="line"></div>Received by<br><strong>{{ $payment->recorder?->name ?? 'Bakong KHQR (online)' }}</strong></div>
        <div class="cell"><div class="line"></div>School</div>
    </div>
</body>
</html>
