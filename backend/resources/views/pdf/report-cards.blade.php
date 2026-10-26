<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('pdf.partials.gradebook-style')
</head>
<body>
{{-- Report cards (docs/academics/gradebook.md, 7d): one page per student, approved results of one class and term. --}}
@php
    $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
@endphp
@foreach ($cards as $card)
<div class="page">
    <div class="head">
        <div class="logo">@if ($logoDataUri)<img src="{{ $logoDataUri }}" alt="">@endif</div>
        <div class="school">
            <h1>{{ $tenant->name }}</h1>
            @if ($tenant->name_en)<p>{{ $tenant->name_en }}</p>@endif
            @if ($tenant->address)<p>{{ $tenant->address }}</p>@endif
        </div>
    </div>

    <div class="title">
        <h2>សៀវភៅតាមដានលទ្ធផលសិក្សា · REPORT CARD</h2>
        <p>{{ $term->name }} · {{ $term->academicYear?->name }}</p>
    </div>

    <div class="info">
        <div class="k">ឈ្មោះ / Name</div><div class="v">{{ $card['name'] }}</div>
        <div class="k">អត្តលេខ / ID</div><div class="v">{{ $card['code'] }}</div>
    </div>
    <div class="info">
        <div class="k">ថ្នាក់ / Class</div><div class="v">{{ $class->name }}@if ($class->gradeLevel) ({{ $class->gradeLevel->name }})@endif</div>
        <div class="k">គ្រូប្រចាំថ្នាក់ / Homeroom</div><div class="v">{{ $homeroom ?? '—' }}</div>
    </div>

    <table class="grid">
        <thead>
            <tr>
                <th style="width:6%">ល.រ<br>No.</th>
                <th>មុខវិជ្ជា<br>Subject</th>
                <th style="width:16%">ពិន្ទុ<br>Score @if ($scheme)/{{ $fmt($scheme['max_score']) }}@endif</th>
                <th style="width:12%">ភាគរយ<br>%</th>
                <th style="width:12%">និទ្ទេស<br>Grade</th>
                <th style="width:14%">លទ្ធផល<br>Result</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($card['subjects'] as $i => $s)
                <tr>
                    <td class="num">{{ $i + 1 }}</td>
                    <td>{{ $s['title'] }}</td>
                    <td class="num">{{ $fmt($s['score']) }}</td>
                    <td class="num">{{ $fmt($s['percent']) }}</td>
                    <td class="num">{{ $s['band'] ?? '—' }}</td>
                    <td class="num @if ($s['passed'] === false) fail @endif">{{ $s['passed'] === null ? '—' : ($s['passed'] ? 'ជាប់ Pass' : 'ធ្លាក់ Fail') }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td></td>
                <td>មធ្យមភាគ · Average</td>
                <td class="num">{{ $fmt($card['score']) }}</td>
                <td class="num">{{ $fmt($card['average']) }}</td>
                <td class="num">{{ $card['band'] ?? '—' }}</td>
                <td class="num @if ($card['passed'] === false) fail @endif">{{ $card['passed'] === null ? '—' : ($card['passed'] ? 'ជាប់ Pass' : 'ធ្លាក់ Fail') }}</td>
            </tr>
        </tbody>
    </table>

    <div class="summary">
        <div><span class="k">មធ្យមភាគ · Average</span><span class="v">{{ $fmt($card['score']) }}</span></div>
        <div><span class="k">និទ្ទេស · Grade</span><span class="v">{{ $card['band'] ?? '—' }}</span></div>
        <div><span class="k">ចំណាត់ថ្នាក់ · Rank</span><span class="v">{{ $card['rank'] ? $card['rank'].' / '.$classSize : '—' }}</span></div>
        <div><span class="k">មុខវិជ្ជាធ្លាក់ · Failed</span><span class="v">{{ $card['failed_subjects'] }}</span></div>
    </div>

    @unless ($card['complete'])
        <p class="note">* លទ្ធផលមិនទាន់គ្រប់មុខវិជ្ជា · Not every subject is approved yet; grade and rank are given once all are.</p>
    @endunless

    <div class="sign">
        <div><div class="line"></div>គ្រូប្រចាំថ្នាក់ · Homeroom teacher</div>
        <div>
            @if ($stampDataUri)<img src="{{ $stampDataUri }}" alt="" style="max-height:60px">@endif
            <div class="line"></div>នាយកសាលា · School director
        </div>
    </div>
    <p class="note">{{ $generatedAt }}</p>
</div>
@endforeach
</body>
</html>
