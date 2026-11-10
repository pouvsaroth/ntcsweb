<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<style>
    {{-- Noto Sans Khmer as data URIs, same reason as invoice.blade.php. --}}
    @font-face { font-family: 'Noto Sans Khmer'; font-weight: normal; src: url('{{ $khmerFontRegular }}'); }
    @font-face { font-family: 'Noto Sans Khmer'; font-weight: bold; src: url('{{ $khmerFontBold }}'); }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Noto Sans Khmer', DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
    .head { display: table; width: 100%; border-bottom: 2px solid #1f2937; padding-bottom: 6px; margin-bottom: 8px; }
    .head > div { display: table-cell; vertical-align: middle; }
    .head .logo { width: 56px; }
    .head .logo img { width: 48px; height: 48px; object-fit: contain; }
    .head h1 { font-size: 14px; margin: 0; }
    .head .title { text-align: right; }
    .head h2 { font-size: 15px; margin: 0; }
    .who { font-size: 12px; font-weight: bold; margin: 0 0 2px; }
    .terms { margin: 0 0 8px; color: #374151; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid thead { display: table-header-group; }
    table.grid tr { page-break-inside: avoid; }
    table.grid th, table.grid td { border: 1px solid #d1d5db; padding: 4px 6px; }
    table.grid th { background: #f3f4f6; font-weight: bold; text-align: center; }
    table.grid td { text-align: right; white-space: nowrap; }
    table.grid td.c { text-align: center; }
    table.grid tfoot td { font-weight: bold; background: #f9fafb; }
    .foot { margin-top: 6px; color: #6b7280; font-size: 9px; }
</style>
</head>
<body>
    <div class="head">
        @if ($logoDataUri)
            <div class="logo"><img src="{{ $logoDataUri }}" alt=""></div>
        @endif
        <div><h1>{{ $tenant->name }}</h1></div>
        <div class="title"><h2>{{ __('customer_loan_plan.title') }}</h2></div>
    </div>

    <p class="who">{{ $plan['heading'] }}</p>
    <p class="terms">{{ $plan['terms'] }}</p>

    <table class="grid">
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($plan['rows'] as $row)
                <tr>
                    <td class="c">{{ $row['no'] }}</td>
                    <td class="c">{{ $row['date'] }}</td>
                    <td>{{ $money($row['start']) }}</td>
                    <td>{{ $money($row['balance']) }}</td>
                    <td>{{ $money($row['principal']) }}</td>
                    <td>{{ $money($row['interest']) }}</td>
                    <td><strong>{{ $money($row['total']) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="c" colspan="4">{{ __('customer_loan_plan.total') }}</td>
                <td>{{ $money($plan['totals']['principal']) }}</td>
                <td>{{ $money($plan['totals']['interest']) }}</td>
                <td>{{ $money($plan['totals']['total']) }}</td>
            </tr>
        </tfoot>
    </table>

    <p class="foot">{{ __('customer_loan_plan.generated') }}: {{ $generatedAt }}</p>
</body>
</html>
