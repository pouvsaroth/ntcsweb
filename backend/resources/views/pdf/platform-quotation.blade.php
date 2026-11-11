<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
<meta charset="utf-8">
<style>
    {{-- Bundled Khmer font, as data URIs — see pdf/receipt.blade.php. --}}
    @font-face { font-family: 'Noto Sans Khmer'; font-style: normal; font-weight: normal; src: url('{{ $khmerFontRegular }}'); }
    @font-face { font-family: 'Noto Sans Khmer'; font-style: normal; font-weight: bold; src: url('{{ $khmerFontBold }}'); }
    body { font-family: 'Noto Sans Khmer', DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; margin: 0; }
    .head { display: table; width: 100%; border-bottom: 2px solid #b45309; padding-bottom: 10px; }
    .head .logo { display: table-cell; width: 70px; vertical-align: middle; }
    .head .logo img { max-height: 56px; max-width: 64px; }
    .head .company { display: table-cell; vertical-align: middle; }
    .head .company h1 { font-size: 15px; margin: 0 0 2px; }
    .head .company p { margin: 0; color: #4b5563; }
    .head .title { display: table-cell; text-align: right; vertical-align: middle; }
    .head .title h2 { font-size: 20px; margin: 0; letter-spacing: 2px; color: #b45309; }
    html[lang="km"] .head .title h2 { letter-spacing: 0; }
    .meta { display: table; width: 100%; margin-top: 14px; }
    .meta .to, .meta .info { display: table-cell; vertical-align: top; }
    .meta .info { width: 42%; }
    .label { color: #6b7280; }
    .to h3 { margin: 2px 0 4px; font-size: 13px; }
    .to p { margin: 1px 0; }
    .info table { width: 100%; border-collapse: collapse; }
    .info td { padding: 2px 0; }
    .info td:last-child { text-align: right; font-weight: bold; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 16px; }
    table.lines th { background: #f3f4f6; border-bottom: 1px solid #d1d5db; padding: 6px; text-align: left; font-size: 10px; }
    table.lines td { border-bottom: 1px solid #e5e7eb; padding: 6px; vertical-align: top; }
    table.lines .num { text-align: right; white-space: nowrap; }
    table.lines tr { page-break-inside: avoid; }
    .module { color: #9ca3af; font-size: 9.5px; }
    .name { font-weight: bold; }
    .desc { color: #4b5563; }
    .totals { width: 46%; margin-left: auto; margin-top: 10px; border-collapse: collapse; }
    .totals td { padding: 3px 6px; }
    .totals td:last-child { text-align: right; white-space: nowrap; }
    .totals tr.grand td { border-top: 2px solid #b45309; font-size: 14px; font-weight: bold; padding-top: 6px; color: #b45309; }
    .notes { margin-top: 18px; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; white-space: pre-line; }
    .notes h4 { margin: 0 0 4px; font-size: 11px; }
    .foot { margin-top: 40px; display: table; width: 100%; page-break-inside: avoid; }
    .foot .cell { display: table-cell; width: 50%; text-align: center; vertical-align: bottom; }
    .foot .line { border-top: 1px solid #9ca3af; width: 60%; margin: 44px auto 4px; }
</style>
</head>
<body>
    @php $date = fn ($d) => $d?->format('d-m-Y'); @endphp

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
        <div class="title"><h2>{{ $l('title') }}</h2></div>
    </div>

    <div class="meta">
        <div class="to">
            <div class="label">{{ $l('to') }}</div>
            <h3>{{ $q->customer_name }}</h3>
            @if($q->contact_name)<p><span class="label">{{ $l('contact') }}:</span> {{ $q->contact_name }}</p>@endif
            @if($q->phone)<p><span class="label">{{ $l('phone') }}:</span> {{ $q->phone }}</p>@endif
            @if($q->email)<p><span class="label">{{ $l('email') }}:</span> {{ $q->email }}</p>@endif
            @if($q->address)<p><span class="label">{{ $l('address') }}:</span> {{ $q->address }}</p>@endif
        </div>
        <div class="info">
            <table>
                <tr><td class="label">{{ $l('number') }}</td><td>{{ $q->number }}</td></tr>
                <tr><td class="label">{{ $l('date') }}</td><td>{{ $date($q->issue_date) }}</td></tr>
                @if($q->valid_until)<tr><td class="label">{{ $l('valid_until') }}</td><td>{{ $date($q->valid_until) }}</td></tr>@endif
            </table>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th style="width: 28px;">{{ $l('no') }}</th>
                <th>{{ $l('item') }}</th>
                <th class="num" style="width: 40px;">{{ $l('qty') }}</th>
                <th class="num" style="width: 90px;">{{ $l('unit_price') }}</th>
                <th class="num" style="width: 96px;">{{ $l('amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lines as $line)
                <tr>
                    <td>{{ $line['no'] }}</td>
                    <td>
                        @if($line['module'])<div class="module">{{ $line['module'] }}</div>@endif
                        <div class="name">{{ $line['name'] }}</div>
                        @if($line['description'])<div class="desc">{{ $line['description'] }}</div>@endif
                    </td>
                    <td class="num">{{ $qty($line['quantity']) }}</td>
                    <td class="num">{{ $money($line['unit_price']) }}</td>
                    <td class="num">{{ $money($line['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="label">{{ $l('subtotal') }}</td><td>{{ $money($q->subtotal) }}</td></tr>
        @if((float) $q->discount_amount > 0)
            <tr><td class="label">{{ $discountLabel }}</td><td>{{ $money(-(float) $q->discount_amount) }}</td></tr>
        @endif
        @if((float) $q->tax_amount > 0)
            <tr><td class="label">{{ $l('tax') }} ({{ $qty($q->tax_percent) }}%)</td><td>{{ $money($q->tax_amount) }}</td></tr>
        @endif
        <tr class="grand"><td>{{ $l('total') }}</td><td>{{ $money($q->total) }}</td></tr>
    </table>

    @if($q->notes)
        <div class="notes"><h4>{{ $l('notes') }}</h4>{{ $q->notes }}</div>
    @endif

    <div class="foot">
        <div class="cell"><div class="line"></div>{{ $l('prepared_by') }}@if($q->staff) — {{ $q->staff->name }}@endif</div>
        <div class="cell"><div class="line"></div>{{ $l('accepted_by') }}</div>
    </div>
</body>
</html>
