<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; }
    .center { text-align: center; }
    h1 { font-size: 16px; margin: 0 0 4px; }
    h2 { font-size: 20px; margin: 16px 0 4px; letter-spacing: 2px; color: #b45309; }
    .box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 16px; margin-top: 16px; }
    .row { display: table; width: 100%; padding: 4px 0; }
    .row .label { display: table-cell; width: 40%; color: #6b7280; }
    .row .value { display: table-cell; width: 60%; text-align: right; font-weight: bold; }
    .amount { text-align: center; font-size: 28px; font-weight: bold; margin: 16px 0; color: #b45309; }
</style>
</head>
<body>
    <div class="center">
        @if($tenant?->logoUrl())<img src="{{ $tenant->logoUrl() }}" alt="" style="max-height:56px;"><br>@endif
        <h1>{{ $tenant?->name ?? config('app.name') }}</h1>
        <h2>RECEIPT</h2>
        <p>{{ $payment->payment_number }}</p>
    </div>

    <div class="box">
        <div class="row"><div class="label">Student</div><div class="value">{{ $invoice->student->student_code }} — {{ $invoice->student->fullName() }}</div></div>
        <div class="row"><div class="label">Invoice</div><div class="value">{{ $invoice->invoice_number }}</div></div>
        <div class="row"><div class="label">Payment Method</div><div class="value">{{ $payment->payment_method }}</div></div>
        <div class="row"><div class="label">Date</div><div class="value">{{ $payment->payment_date->format('d M Y') }}</div></div>
        @if($payment->reference_number)
            <div class="row"><div class="label">Reference</div><div class="value">{{ $payment->reference_number }}</div></div>
        @endif
    </div>

    <div class="amount">${{ number_format((float) $payment->amount, 2) }}</div>

    <div class="box">
        <div class="row"><div class="label">Invoice Total</div><div class="value">${{ number_format((float) $invoice->total, 2) }}</div></div>
        <div class="row"><div class="label">Remaining Balance</div><div class="value">${{ number_format((float) $invoice->balance, 2) }}</div></div>
    </div>
</body>
</html>
