@php
    use App\Services\Accounting\ExpenseSupportReport;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<style>
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
    h3 { font-size: 12px; margin: 10px 0 4px; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid thead { display: table-header-group; }
    table.grid tr { page-break-inside: avoid; }
    table.grid th, table.grid td { border: 1px solid #d1d5db; padding: 3px 5px; text-align: left; vertical-align: top; }
    table.grid th { background: #f3f4f6; font-weight: bold; white-space: nowrap; }
    table.grid td.num, table.grid th.num { text-align: right; white-space: nowrap; }
    table.grid td.no { text-align: center; width: 28px; }
    table.grid td.nowrap { white-space: nowrap; }
    table.summary { width: auto; min-width: 60%; }
    .net { font-weight: bold; }
    .empty { padding: 24px; text-align: center; color: #6b7280; }
    .foot { margin-top: 6px; color: #6b7280; font-size: 9px; }
</style>
</head>
<body>
    <div class="head">
        @if ($logoDataUri)
            <div class="logo"><img src="{{ $logoDataUri }}" alt=""></div>
        @endif
        <div><h1>{{ $tenant->name }}</h1></div>
        <div class="title"><h2>{{ __('expense_support.title') }}{{ $period }}</h2></div>
    </div>

    @if (count($report['summary']))
        <table class="grid summary">
            <thead>
                <tr>
                    <th rowspan="2">{{ __('expense_support.expense_for') }}</th>
                    <th class="num" colspan="2">{{ __('expense_support.expenses') }}</th>
                    <th class="num" colspan="2">{{ __('expense_support.support_money') }}</th>
                    <th class="num" colspan="2">{{ __('expense_support.net') }}</th>
                    <th class="num" rowspan="2">{{ __('expense_support.people') }}</th>
                </tr>
                <tr>
                    <th class="num">USD</th><th class="num">KHR</th>
                    <th class="num">USD</th><th class="num">KHR</th>
                    <th class="num">USD</th><th class="num">KHR</th>
                </tr>
            </thead>
            <tbody>
                {{-- Each expense / support converted at the rate on its own date. --}}
                @foreach ($report['summary'] as $block)
                    @php($t = $block['totals'])
                    <tr>
                        <td>{{ $block['expense_entity']['name'] }}</td>
                        <td class="num">{{ ExpenseSupportReport::money($t['expenses_usd'], 'USD') }}</td>
                        <td class="num">{{ ExpenseSupportReport::money($t['expenses_khr'], 'KHR') }}</td>
                        <td class="num">{{ ExpenseSupportReport::money($t['support_usd'], 'USD') }}</td>
                        <td class="num">{{ ExpenseSupportReport::money($t['support_khr'], 'KHR') }}</td>
                        <td class="num net">{{ ExpenseSupportReport::money($t['net_usd'], 'USD') }}</td>
                        <td class="num net">{{ ExpenseSupportReport::money($t['net_khr'], 'KHR') }}</td>
                        <td class="num">{{ __('expense_support.people_line', ['money' => $block['money_count'], 'food' => $block['food_count']]) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h3>{{ __('expense_support.list') }} ({{ count($report['rows']) }})</h3>
    @if (count($report['rows']) === 0)
        <div class="empty">{{ __('expense_support.empty') }}</div>
    @else
        <table class="grid">
            <thead>
                <tr>
                    <th>{{ __('expense_support.no') }}</th>
                    <th>{{ __('expense_support.date') }}</th>
                    <th>{{ __('expense_support.name') }}</th>
                    <th>{{ __('expense_support.phone') }}</th>
                    <th>{{ __('expense_support.relationship') }}</th>
                    <th>{{ __('expense_support.kind') }}</th>
                    <th>{{ __('expense_support.items') }}</th>
                    <th class="num">{{ __('expense_support.amount') }} (USD)</th>
                    <th class="num">{{ __('expense_support.amount') }} (KHR)</th>
                    <th>{{ __('expense_support.expense_for') }}</th>
                    <th>{{ __('expense_support.note') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['rows'] as $i => $row)
                    <tr>
                        <td class="no">{{ $i + 1 }}</td>
                        <td class="nowrap">{{ $row['support_date'] }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td class="nowrap">{{ $row['phone'] }}</td>
                        <td>{{ $row['relationship'] }}</td>
                        <td>{{ $row['kind'] }}</td>
                        <td>{{ $row['items'] }}</td>
                        <td class="num nowrap">{{ $row['USD'] }}</td>
                        <td class="num nowrap">{{ $row['KHR'] }}</td>
                        <td>{{ $row['expense_for'] }}</td>
                        <td>{{ $row['note'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="foot">{{ __('expense_support.generated') }}: {{ $generatedAt }}</p>
</body>
</html>
