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
    {{-- The page's own 1cm margin comes from Browsershot's `margin` PDF
         option (BrowsershotRenderer/InvoicePdfService), not CSS — this reset
         just stops the browser's own default body margin from stacking on
         top of it. --}}
    body { margin: 0; font-family: 'Noto Sans Khmer', DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
    .header { display: table; width: 100%; margin-bottom: 16px; }
    .header .school { display: table-cell; width: 60%; vertical-align: top; }
    .header .school img { max-height: 44px; margin-bottom: 6px; }
    .header .school h1 { font-size: 14px; margin: 0 0 4px; }
    .header .school p { margin: 0; color: #6b7280; }
    .header .invoice { display: table-cell; width: 40%; text-align: right; vertical-align: top; }
    .header .invoice h2 { font-size: 18px; margin: 0 0 6px; color: #dc2626; }
    .header .invoice p { margin: 0 0 2px; }
    .status { display: inline-block; padding: 2px 8px; border-radius: 4px; background: #fef3c7; color: #92400e; font-size: 10px; }
    .status-paid { background: transparent; color: #dc2626; font-weight: bold; padding: 0; }
    .meta { display: table; width: 100%; margin-bottom: 16px; }
    .meta .student { display: table-cell; width: 60%; vertical-align: top; }
    .meta .dates { display: table-cell; width: 40%; text-align: right; vertical-align: top; }
    .meta h3 { font-size: 10px; text-transform: uppercase; color: #6b7280; margin: 0 0 4px; }
    .meta p { margin: 0 0 3px; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.items, table.items th, table.items td { border: 1px solid #1f2937; }
    table.items th { text-align: left; padding: 5px 4px; font-size: 10px; text-transform: uppercase; background: #f3f4f6; }
    table.items td { padding: 5px 4px; }
    table.items th.num, table.items td.num { text-align: right; }
    .totals { width: 45%; margin-left: 55%; margin-bottom: 14px; }
    .totals table { width: 100%; border-collapse: collapse; }
    .totals td { padding: 3px 0; }
    .totals td.label { color: #6b7280; }
    .totals td.value { text-align: right; }
    .totals tr.total td { border-top: 2px solid #1f2937; font-weight: bold; font-size: 13px; padding-top: 6px; }
    .totals tr.balance td { font-weight: bold; color: #dc2626; }
    table.payments { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    table.payments th { text-align: left; border-bottom: 2px solid #1f2937; padding: 5px 4px; font-size: 10px; text-transform: uppercase; }
    table.payments td { padding: 5px 4px; border-bottom: 1px solid #e5e7eb; }
    table.payments th.num, table.payments td.num { text-align: right; }
    .notes { margin: 14px 0; color: #6b7280; }
    .signoff { display: table; width: 100%; margin-top: 24px; }
    .signoff .stamp { display: table-cell; width: 50%; vertical-align: bottom; }
    .signoff .stamp img { max-height: 80px; }
    .signoff .signature { display: table-cell; width: 50%; text-align: center; vertical-align: bottom; }
    .signoff .signature img { max-height: 48px; margin-bottom: 2px; }
    .signoff .signature .line { border-top: 1px solid #9ca3af; width: 70%; margin: 4px auto 4px; }
    .signoff .signature .title { font-weight: bold; margin: 0; }
    .signoff .signature .name { margin: 2px 0 0; color: #374151; }
</style>
</head>
<body>
    {{-- The invoice's own currency, not always USD — see EnrollmentService
         for a school billed in Riel. KHR is a rounded whole number by
         convention (no subunit in everyday use); USD keeps two decimals. --}}
    @php
        $money = fn (float $amount) => $invoice->currency === 'KHR'
            ? number_format(round($amount)).' ៛'
            : '$'.number_format($amount, 2);

        // Only present when this invoice came from an enrollment (see
        // EnrollmentService) — a manually-created invoice has no class to
        // show, and that's fine: the block below just doesn't render.
        $enrollmentItem = $invoice->items->first(
            fn ($item) => $item->reference_type === \App\Models\Enrollment::class && $item->reference !== null
        );
        $enrolledClass = $enrollmentItem?->reference?->schoolClass;
        $classTime = $enrolledClass?->schedules
            ?->map(fn ($s) => "{$s->dayName()} {$s->start_time}–{$s->end_time}")
            ->implode(', ');
    @endphp
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
            <p>
                <span class="status @if($invoice->status === \App\Support\Billing\InvoiceStatus::PAID) status-paid @endif">
                    {{ __('invoice.statuses.'.strtolower($invoice->status)) }}
                </span>
            </p>
        </div>
    </div>

    <div class="meta">
        <div class="student">
            <h3>{{ __('invoice.bill_to') }}</h3>
            <p>{{ __('invoice.student_id') }}: <strong>{{ $invoice->student->student_code }}</strong></p>
            <p>{{ __('invoice.name') }}: <strong>{{ $invoice->student->fullName() }}</strong></p>
            @if($invoice->student->english_name)
                <p>{{ __('invoice.english_name') }}: {{ $invoice->student->english_name }}</p>
            @endif
            @if($invoice->student->phone)
                <p>{{ __('invoice.tel') }}: {{ $invoice->student->phone }}</p>
            @endif
        </div>
        <div class="dates">
            <h3>{{ __('invoice.invoice_date') }}</h3>
            <p>{{ $invoice->invoice_date->format('d M Y') }}</p>
            @if($enrolledClass)
                <h3 style="margin-top:8px;">{{ __('invoice.class') }}</h3>
                <p>{{ $enrolledClass->name }}</p>
                <h3 style="margin-top:8px;">{{ __('invoice.time') }}</h3>
                <p>{{ $classTime ?: '—' }}</p>
            @endif
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
                    <td class="num">{{ $money((float) $item->unit_price) }}</td>
                    <td class="num">{{ $money((float) $item->discount) }}</td>
                    <td class="num">{{ $money((float) $item->total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <table>
            <tr><td class="label">{{ __('invoice.subtotal') }}</td><td class="value">{{ $money((float) $invoice->subtotal) }}</td></tr>
            <tr><td class="label">{{ __('invoice.discount') }}</td><td class="value">{{ $money((float) $invoice->discount) }}</td></tr>
            <tr><td class="label">{{ __('invoice.tax') }}</td><td class="value">{{ $money((float) $invoice->tax) }}</td></tr>
            <tr class="total"><td class="label">{{ __('invoice.total') }}</td><td class="value">{{ $money((float) $invoice->total) }}</td></tr>
            <tr><td class="label">{{ __('invoice.paid') }}</td><td class="value">{{ $money((float) $invoice->paid_amount) }}</td></tr>
            <tr class="balance"><td class="label">{{ __('invoice.balance') }}</td><td class="value">{{ $money((float) $invoice->balance) }}</td></tr>
        </table>
    </div>

    @if($invoice->payments->isNotEmpty())
        <table class="payments">
            <thead>
                <tr><th>{{ __('invoice.payment_history') }}</th><th class="num">{{ __('invoice.method') }}</th><th class="num">{{ __('invoice.date') }}</th><th class="num">{{ __('invoice.amount') }}</th></tr>
            </thead>
            <tbody>
                @foreach($invoice->payments as $payment)
                    <tr>
                        <td>{{ $payment->payment_number }}</td>
                        <td class="num">{{ __('invoice.methods.'.strtolower($payment->payment_method)) }}</td>
                        <td class="num">{{ $payment->payment_date->format('d M Y') }}</td>
                        <td class="num">{{ $money((float) $payment->amount) }}</td>
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

    {{-- The staff member who actually issued this invoice (Invoice::createdBy)
         signs it — never a fixed "always the director" signer. Falls back to
         the plain account name when that user has no linked Staff profile
         (and so no position/signature of their own) rather than showing
         nothing at all. --}}
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
            <p class="name">{{ $issuerStaff?->fullName() ?? $invoice->createdBy?->name }}</p>
        </div>
    </div>
</body>
</html>
