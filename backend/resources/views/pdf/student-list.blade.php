@php
    $columns = $list['columns'];
    $wide = ['address', 'guardian', 'class', 'course'];
@endphp
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
    .totals { margin: 0 0 8px; font-size: 12px; font-weight: bold; }
    .totals span { display: inline-block; border: 1px solid #d1d5db; border-radius: 4px; padding: 3px 10px; margin-right: 8px; background: #f9fafb; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid thead { display: table-header-group; }
    table.grid tr { page-break-inside: avoid; }
    table.grid th, table.grid td { border: 1px solid #d1d5db; padding: 3px 5px; text-align: left; vertical-align: top; }
    table.grid th { background: #f3f4f6; font-weight: bold; white-space: nowrap; }
    table.grid td.no { text-align: center; width: 28px; }
    table.grid td.nowrap { white-space: nowrap; }
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
        <div class="title"><h2>{{ __('student_list.title') }}</h2></div>
    </div>

    <p class="totals">
        <span>{{ __('student_list.total') }}: {{ $list['total'] }}</span>
        <span>{{ __('student_list.female') }}: {{ $list['female'] }}</span>
    </p>

    @if ($list['total'] === 0)
        <div class="empty">{{ __('student_list.empty') }}</div>
    @else
        <table class="grid">
            <thead>
                <tr>
                    <th>{{ __('student_list.no') }}</th>
                    @foreach ($columns as $column)
                        <th>{{ __('student_list.columns.'.$column) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($list['rows'] as $i => $row)
                    <tr>
                        <td class="no">{{ $i + 1 }}</td>
                        @foreach ($columns as $column)
                            <td class="{{ in_array($column, $wide, true) ? '' : 'nowrap' }}">{{ $row[$column] ?? '' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="foot">{{ __('student_list.generated') }}: {{ $generatedAt }}</p>
</body>
</html>
