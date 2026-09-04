<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- Static instances of Noto Sans Khmer (Regular/Bold) bundled under resources/fonts,
         handed in as base64 data URIs by InvoicePdfService — see its docblock for why
         (Browsershot blocks `file://` in HTML outright, and `http://localhost:8080` isn't
         reachable from inside this container). Chromium's default fonts have no Khmer
         glyphs, so any Khmer text (school name, student name, notes, ...) would render
         blank/tofu without this. Latin/numerals are covered by the same font, so it's the
         only family the body needs. --}}
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
    .header { display: table; width: 100%; margin-bottom: 24px; }
    .header .school { display: table-cell; width: 60%; vertical-align: top; }
    .header .school img { max-height: 56px; margin-bottom: 6px; }
    .header .school h1 { font-size: 16px; margin: 0 0 4px; }
    .header .school p { margin: 0; color: #6b7280; }
    .header .invoice { display: table-cell; width: 40%; text-align: right; vertical-align: top; }
    .header .invoice h2 { font-size: 22px; margin: 0 0 6px; color: #b45309; }
    .header .invoice p { margin: 0; }
    .meta { display: table; width: 100%; margin-bottom: 20px; }
    .meta .student { display: table-cell; width: 60%; }
    .meta .dates { display: table-cell; width: 40%; text-align: right; }
    .meta h3 { font-size: 11px; text-transform: uppercase; color: #6b7280; margin: 0 0 4px; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    table.items th { text-align: left; border-bottom: 2px solid #1f2937; padding: 6px 4px; font-size: 11px; text-transform: uppercase; }
    table.items td { padding: 6px 4px; border-bottom: 1px solid #e5e7eb; }
    table.items th.num, table.items td.num { text-align: right; }
    .totals { width: 40%; margin-left: 60%; }
    .totals table { width: 100%; border-collapse: collapse; }
    .totals td { padding: 4px 0; }
    .totals td.label { color: #6b7280; }
    .totals td.value { text-align: right; }
    .totals tr.total td { border-top: 2px solid #1f2937; font-weight: bold; font-size: 14px; padding-top: 8px; }
    .totals tr.balance td { font-weight: bold; color: #b45309; }
    .notes { margin-top: 24px; color: #6b7280; }
    .status { display: inline-block; padding: 2px 8px; border-radius: 4px; background: #fef3c7; color: #92400e; font-size: 11px; }
</style>
</head>
<body>
    <div class="header">
        <div class="school">
            @if($logoDataUri)
                <img src="{{ $logoDataUri }}" alt="">
            @endif
            <h1>{{ $tenant?->name ?? config('app.name') }}</h1>
            @if($tenant?->address)<p>{{ $tenant->address }}</p>@endif
            @if($tenant?->phone)<p>{{ $tenant->phone }}</p>@endif
            @if($tenant?->email)<p>{{ $tenant->email }}</p>@endif
        </div>
        <div class="invoice">
            <h2>{{ __('invoice.invoice') }}</h2>
            <p><strong>{{ $invoice->invoice_number }}</strong></p>
            <p><span class="status">{{ __('invoice.statuses.'.strtolower($invoice->status)) }}</span></p>
        </div>
    </div>

    <div class="meta">
        <div class="student">
            <h3>{{ __('invoice.bill_to') }}</h3>
            <p><strong>{{ $invoice->student->student_code }} — {{ $invoice->student->fullName() }}</strong></p>
            @if($invoice->student->phone)<p>{{ $invoice->student->phone }}</p>@endif
        </div>
        <div class="dates">
            <h3>{{ __('invoice.invoice_date') }}</h3>
            <p>{{ $invoice->invoice_date->format('d M Y') }}</p>
            @if($invoice->due_date)
                <h3 style="margin-top:8px;">{{ __('invoice.due_date') }}</h3>
                <p>{{ $invoice->due_date->format('d M Y') }}</p>
            @endif
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>{{ __('invoice.description') }}</th>
                <th class="num">{{ __('invoice.qty') }}</th>
                <th class="num">{{ __('invoice.unit_price') }}</th>
                <th class="num">{{ __('invoice.discount') }}</th>
                <th class="num">{{ __('invoice.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">${{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="num">${{ number_format((float) $item->discount, 2) }}</td>
                    <td class="num">${{ number_format((float) $item->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <table>
            <tr><td class="label">{{ __('invoice.subtotal') }}</td><td class="value">${{ number_format((float) $invoice->subtotal, 2) }}</td></tr>
            <tr><td class="label">{{ __('invoice.discount') }}</td><td class="value">${{ number_format((float) $invoice->discount, 2) }}</td></tr>
            <tr><td class="label">{{ __('invoice.tax') }}</td><td class="value">${{ number_format((float) $invoice->tax, 2) }}</td></tr>
            <tr class="total"><td class="label">{{ __('invoice.total') }}</td><td class="value">${{ number_format((float) $invoice->total, 2) }}</td></tr>
            <tr><td class="label">{{ __('invoice.paid') }}</td><td class="value">${{ number_format((float) $invoice->paid_amount, 2) }}</td></tr>
            <tr class="balance"><td class="label">{{ __('invoice.balance') }}</td><td class="value">${{ number_format((float) $invoice->balance, 2) }}</td></tr>
        </table>
    </div>

    @if($invoice->payments->isNotEmpty())
        <table class="items">
            <thead>
                <tr><th>{{ __('invoice.payment_history') }}</th><th class="num">{{ __('invoice.method') }}</th><th class="num">{{ __('invoice.date') }}</th><th class="num">{{ __('invoice.amount') }}</th></tr>
            </thead>
            <tbody>
                @foreach($invoice->payments as $payment)
                    <tr>
                        <td>{{ $payment->payment_number }}</td>
                        <td class="num">{{ __('invoice.methods.'.strtolower($payment->payment_method)) }}</td>
                        <td class="num">{{ $payment->payment_date->format('d M Y') }}</td>
                        <td class="num">${{ number_format((float) $payment->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($invoice->notes)
        <div class="notes">
            <h3>{{ __('invoice.notes') }}</h3>
            <p>{{ $invoice->notes }}</p>
        </div>
    @endif
</body>
</html>
