@php
    use App\Services\Academic\AttendanceSheet as Sheet;
    use Illuminate\Support\Carbon;
    $dates = $sheet['dates'];
    $markClass = function (string $mark): string {
        return match (true) {
            $mark === '' => '',
            $mark === Sheet::PRESENT => 'm-present',
            $mark === Sheet::ABSENT => 'm-absent',
            $mark === Sheet::PERMISSION => 'm-permission',
            $mark === Sheet::NOT_STARTED => 'm-not-started',
            default => 'm-late',
        };
    };
    // Narrow day columns get smaller text so a month still fits across A4 landscape.
    $dense = count($dates) > 20;
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
    body { margin: 0; font-family: 'Noto Sans Khmer', DejaVu Sans, sans-serif; font-size: {{ $dense ? '9px' : '10px' }}; color: #111827; }
    .head { display: table; width: 100%; border-bottom: 2px solid #1f2937; padding-bottom: 6px; margin-bottom: 8px; }
    .head > div { display: table-cell; vertical-align: middle; }
    .head .logo { width: 56px; }
    .head .logo img { width: 48px; height: 48px; object-fit: contain; }
    .head h1 { font-size: 14px; margin: 0; }
    .head p { margin: 0; color: #4b5563; }
    .head .title { text-align: right; }
    .head h2 { font-size: 15px; margin: 0; }
    .legend { margin: 0 0 6px; color: #374151; }
    .legend span { margin-right: 14px; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid thead { display: table-header-group; }
    table.grid tr { page-break-inside: avoid; }
    table.grid th, table.grid td { border: 1px solid #d1d5db; padding: 2px 3px; text-align: center; white-space: nowrap; }
    table.grid th { background: #f3f4f6; font-weight: bold; }
    table.grid th.day { font-size: {{ $dense ? '8px' : '9px' }}; line-height: 1.15; }
    table.grid td.name { text-align: left; white-space: normal; min-width: 120px; }
    table.grid th.total, table.grid td.total { background: #f9fafb; font-weight: bold; }
    .m-present { color: #15803d; }
    .m-absent { color: #b91c1c; background: #fee2e2; font-weight: bold; }
    .m-permission { color: #1d4ed8; background: #dbeafe; font-weight: bold; }
    .m-late { color: #92400e; background: #fef3c7; font-weight: bold; }
    .m-not-started { color: #9ca3af; background: #f3f4f6; }
    .empty { padding: 24px; text-align: center; color: #6b7280; }
    .foot { margin-top: 6px; color: #6b7280; font-size: 9px; }
</style>
</head>
<body>
    <div class="head">
        @if ($logoDataUri)
            <div class="logo"><img src="{{ $logoDataUri }}" alt=""></div>
        @endif
        <div>
            <h1>{{ $tenant->name }}</h1>
            <p>{{ __('attendance_sheet.class') }}: <strong>{{ $sheet['class']['name'] }}</strong> · {{ __('attendance_sheet.period') }}: <strong>{{ $period }}</strong></p>
        </div>
        <div class="title">
            <h2>{{ __('attendance_sheet.title') }}</h2>
            <p>{{ __('attendance_sheet.students') }}: {{ count($sheet['students']) }} · {{ __('attendance_sheet.class_days') }}: {{ count($dates) }}</p>
        </div>
    </div>

    <p class="legend">{{ $legend }}</p>

    @if (count($dates) === 0)
        <div class="empty">{{ __('attendance_sheet.no_days') }}</div>
    @else
        <table class="grid">
            <thead>
                <tr>
                    <th>{{ __('attendance_sheet.no') }}</th>
                    <th>{{ __('attendance_sheet.student') }}</th>
                    @foreach ($dates as $date)
                        @php $d = Carbon::parse($date); @endphp
                        <th class="day">{{ $d->format('d') }}<br>{{ $d->format('m') }}</th>
                    @endforeach
                    @foreach ($totalLabels as $label)
                        <th class="total">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($sheet['students'] as $i => $row)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td class="name">{{ $row['student']['name'] }}</td>
                        @foreach ($dates as $date)
                            @php $mark = $row['marks'][$date] ?? ''; @endphp
                            <td class="{{ $markClass($mark) }}">{{ $mark }}</td>
                        @endforeach
                        @foreach ($row['totals'] as $value)
                            <td class="total">{{ $value }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="foot">{{ __('attendance_sheet.generated') }}: {{ $generatedAt }}</p>
</body>
</html>
