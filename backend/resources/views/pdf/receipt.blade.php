<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- See invoice.blade.php's own @font-face docblock — same reasoning, now that this renders via Browsershot too. --}}
    @font-face {
        font-family: 'Noto Sans Khmer';
        font-style: normal;
        font-weight: normal;
        src: url('{{ $khmerFontRegular }}');
    }
    @font-face {
        font-family: 'Noto Sans Khmer';
        font-style: normal;
        font-weight: bold;
        src: url('{{ $khmerFontBold }}');
    }
    body { font-family: 'Noto Sans Khmer', DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; }
    .center { text-align: center; }
    h1 { font-size: 16px; margin: 0 0 4px; }
    h2 { font-size: 20px; margin: 16px 0 4px; letter-spacing: 2px; color: #b45309; }
    .box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 16px; margin-top: 16px; }
    .row { display: table; width: 100%; padding: 4px 0; }
    .row .label { display: table-cell; width: 40%; color: #6b7280; }
    .row .value { display: table-cell; width: 60%; text-align: right; font-weight: bold; }
    .amount { text-align: center; font-size: 28px; font-weight: bold; margin: 16px 0; color: #b45309; }
    .signoff { display: table; width: 100%; margin-top: 28px; }
    .signoff .stamp { display: table-cell; width: 50%; vertical-align: bottom; }
    .signoff .stamp img { max-height: 70px; }
    .signoff .signature { display: table-cell; width: 50%; text-align: center; vertical-align: bottom; }
    .signoff .signature img { max-height: 44px; margin-bottom: 2px; }
    .signoff .signature .line { border-top: 1px solid #9ca3af; width: 70%; margin: 4px auto 4px; }
    .signoff .signature .title { font-weight: bold; margin: 0; }
    .signoff .signature .name { margin: 2px 0 0; color: #374151; }
</style>
</head>
<body>
    {{-- See invoice.blade.php's own $money helper docblock. --}}
    @php
        $money = fn (float $amount) => $invoice->currency === 'KHR'
            ? number_format(round($amount)).' ៛'
            : '$'.number_format($amount, 2);
    @endphp
    <div class="center">
        @if($logoDataUri)<img src="{{ $logoDataUri }}" alt="" style="max-height:56px;"><br>@endif
        <h1>{{ $tenant?->name ?? config('app.name') }}</h1>
        <h2>RECEIPT</h2>
        <p>{{ $payment->payment_number }}</p>
    </div>

    <div class="box">
        <div class="row"><div class="label">Student</div><div class="value">{{ $invoice->student->student_code }} — {{ $invoice->student->fullName() }}</div></div>
        <div class="row"><div class="label">Invoice</div><div class="value">{{ $invoice->invoice_number }}</div></div>
        <div class="row"><div class="label">Payment Method</div><div class="value">{{ $payment->payment_method }}</div></div>
        <div class="row"><div class="label">Date</div><div class="value">{{ $payment->payment_date->format('d-m-Y') }}</div></div>
        @if($payment->reference_number)
            <div class="row"><div class="label">Reference</div><div class="value">{{ $payment->reference_number }}</div></div>
        @endif
    </div>

    <div class="amount">{{ $money((float) $payment->amount) }}</div>

    <div class="box">
        <div class="row"><div class="label">Invoice Total</div><div class="value">{{ $money((float) $invoice->total) }}</div></div>
        <div class="row"><div class="label">Remaining Balance</div><div class="value">{{ $money((float) $invoice->balance) }}</div></div>
    </div>

    {{-- The staff member who actually recorded this payment (Payment::receivedBy) signs it — see invoice.blade.php's identical block. --}}
    <div class="signoff">
        <div class="stamp">
            @if($stampDataUri)
                <img src="{{ $stampDataUri }}" alt="">
            @endif
        </div>
        <div class="signature">
            @if($signatureDataUri)
                <img src="{{ $signatureDataUri }}" alt="">
            @endif
            <div class="line"></div>
            @if($issuerStaff?->position?->name)
                <p class="title">{{ $issuerStaff->position->name }}</p>
            @endif
            <p class="name">{{ $issuerStaff?->fullName() ?? $payment->receivedBy?->name }}</p>
        </div>
    </div>
</body>
</html>
