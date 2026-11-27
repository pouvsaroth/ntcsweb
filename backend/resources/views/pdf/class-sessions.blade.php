@php
    $summaryColumns = ['planned', 'taught', 'late', 'covered', 'not_held', 'missed', 'no_record', 'covering'];
    $tone = ['taught' => '#15803d', 'covering' => '#1d4ed8', 'covered' => '#6b7280', 'not_held' => '#b45309', 'missed' => '#b91c1c', 'no_record' => '#b45309'];
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
    .head p { margin: 2px 0 0; color: #4b5563; }
    h3 { font-size: 12px; margin: 10px 0 4px; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid thead { display: table-header-group; }
    table.grid tr { page-break-inside: avoid; }
    table.grid th, table.grid td { border: 1px solid #d1d5db; padding: 3px 5px; text-align: left; vertical-align: top; }
    table.grid th { background: #f3f4f6; font-weight: bold; white-space: nowrap; }
    table.grid td.num, table.grid th.num { text-align: right; }
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
        <div class="title"><h2>{{ __('class_sessions.title') }}</h2><p>{{ $period }}</p></div>
    </div>

    @if (count($report['rows']) === 0 && count($report['summary']) === 0)
        <div class="empty">{{ __('class_sessions.empty') }}</div>
    @else
        <h3>{{ __('class_sessions.summary') }}</h3>
        <table class="grid">
            <thead>
                <tr>
                    <th>{{ __('class_sessions.columns.no') }}</th>
                    <th>{{ __('class_sessions.columns.teacher') }}</th>
                    @foreach ($summaryColumns as $column)
                        <th class="num">{{ __('class_sessions.columns.'.$column) }}</th>
                    @endforeach
                    <th class="num">{{ __('class_sessions.columns.taught_hours') }}</th>
                    <th class="num">{{ __('class_sessions.columns.taught_percent') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['summary'] as $i => $s)
                    <tr>
                        <td class="no">{{ $i + 1 }}</td>
                        <td>{{ $s['teacher'] }}</td>
                        @foreach ($summaryColumns as $column)
                            <td class="num">{{ $s[$column] }}</td>
                        @endforeach
                        <td class="num">{{ round($s['taught_minutes'] / 60, 2) }}</td>
                        <td class="num">{{ $s['taught_percent'] === null ? '—' : $s['taught_percent'].'%' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h3>{{ __('class_sessions.sessions') }}</h3>
        <table class="grid">
            <thead>
                <tr>
                    @foreach (['no', 'date', 'time', 'class', 'subject', 'teacher', 'status', 'detail', 'check_in'] as $column)
                        <th>{{ __('class_sessions.columns.'.$column) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($report['rows'] as $i => $r)
                    <tr>
                        <td class="no">{{ $i + 1 }}</td>
                        <td class="nowrap">{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d-m-Y') }}</td>
                        <td class="nowrap">{{ $r['start_time'] }}–{{ $r['end_time'] }}</td>
                        <td>{{ $r['class'] }}</td>
                        <td>{{ $r['subject'] }}</td>
                        <td>{{ $r['teacher'] ?? '—' }}</td>
                        <td class="nowrap" style="color: {{ $tone[$r['status']] ?? '#111827' }}; font-weight: bold;">{{ __('class_sessions.status.'.$r['status']) }}</td>
                        <td>{{ $r['detail'] }}{{ $r['late_minutes'] > 0 ? ' · '.__('class_sessions.columns.late').' '.$r['late_minutes'].' min' : '' }}</td>
                        <td class="nowrap">{{ $r['check_in'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="foot">{{ __('class_sessions.generated') }}: {{ $generatedAt }}</p>
</body>
</html>
