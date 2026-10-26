<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('pdf.partials.gradebook-style')
</head>
<body>
{{-- Academic transcript (docs/academics/gradebook.md, 7d): approved results, year by year and term by term. --}}
@php
    $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    $result = fn ($p) => $p === null ? '—' : ($p ? 'ជាប់ Pass' : 'ធ្លាក់ Fail');
@endphp
<div class="head">
    <div class="logo">@if ($logoDataUri)<img src="{{ $logoDataUri }}" alt="">@endif</div>
    <div class="school">
        <h1>{{ $tenant->name }}</h1>
        @if ($tenant->name_en)<p>{{ $tenant->name_en }}</p>@endif
        @if ($tenant->address)<p>{{ $tenant->address }}</p>@endif
    </div>
</div>

<div class="title">
    <h2>ប្រវត្តិពិន្ទុ · ACADEMIC TRANSCRIPT</h2>
</div>

<div class="info">
    <div class="k">ឈ្មោះ / Name</div><div class="v">{{ $transcript['student']['name'] }}@if ($transcript['student']['english_name']) ({{ $transcript['student']['english_name'] }})@endif</div>
    <div class="k">អត្តលេខ / ID</div><div class="v">{{ $transcript['student']['code'] }}</div>
</div>

@forelse ($transcript['years'] as $year)
    <h3>ឆ្នាំសិក្សា · Academic year {{ $year['academic_year']['name'] }} — {{ $year['class']['name'] }}@if ($year['class']['grade_level']) ({{ $year['class']['grade_level'] }})@endif</h3>
    @php
        $subjects = collect($year['terms'])->flatMap(fn ($t) => collect($t['subjects'])->map(fn ($s) => ['id' => $s['id'], 'title' => $s['title']]))->unique('id')->values();
    @endphp
    <table class="grid">
        <thead>
            <tr>
                <th>មុខវិជ្ជា · Subject</th>
                @foreach ($year['terms'] as $t)<th style="width:13%">{{ $t['term']['name'] }}</th>@endforeach
                <th style="width:14%">ប្រចាំឆ្នាំ · Year</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($subjects as $sub)
                <tr>
                    <td>{{ $sub['title'] }}</td>
                    @foreach ($year['terms'] as $t)
                        @php $c = collect($t['subjects'])->firstWhere('id', $sub['id']); @endphp
                        <td class="num @if ($c && $c['passed'] === false) fail @endif">{{ $c ? $fmt($c['score']).($c['band'] ? ' · '.$c['band'] : '') : '—' }}</td>
                    @endforeach
                    @php $y = collect($year['year']['subjects'])->firstWhere('id', $sub['id']); @endphp
                    <td class="num">{{ $y ? $fmt($y['score']) : '—' }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>មធ្យមភាគ · Average</td>
                @foreach ($year['terms'] as $t)<td class="num">{{ $fmt($t['score']) }}{{ $t['band'] ? ' · '.$t['band'] : '' }}</td>@endforeach
                <td class="num">{{ $fmt($year['year']['score']) }}{{ $year['year']['band'] ? ' · '.$year['year']['band'] : '' }}</td>
            </tr>
            <tr>
                <td>ចំណាត់ថ្នាក់ · Rank</td>
                @foreach ($year['terms'] as $t)<td class="num">{{ $t['rank'] ? $t['rank'].' / '.$t['class_size'] : '—' }}</td>@endforeach
                <td class="num">{{ $result($year['year']['passed']) }}</td>
            </tr>
        </tbody>
    </table>
@empty
    <p class="muted">មិនទាន់មានលទ្ធផលដែលបានអនុម័ត · No approved results yet.</p>
@endforelse

<p class="note">ពិន្ទុគិតលើ {{ $scaleNote }} · Scores out of {{ $scaleNote }}. Only approved results are shown. {{ $generatedAt }}</p>

<div class="sign">
    <div></div>
    <div>
        @if ($stampDataUri)<img src="{{ $stampDataUri }}" alt="" style="max-height:60px">@endif
        <div class="line"></div>នាយកសាលា · School director
    </div>
</div>
</body>
</html>
