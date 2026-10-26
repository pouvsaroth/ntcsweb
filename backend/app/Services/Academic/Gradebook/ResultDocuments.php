<?php

declare(strict_types=1);

namespace App\Services\Academic\Gradebook;

use App\Models\AcademicTerm;
use App\Models\Book;
use App\Models\ResultSheet;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\KhmerFont;
use App\Services\Pdf\PdfImageEncoder;
use App\Support\Tenancy\TenantContext;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Result Export and the transcript PDF (docs/academics/gradebook.md, 7d).
 *
 * - Report cards: one A4 page per student, approved results of one class and term.
 * - Transcript: a student's approved results, year by year.
 * - Score sheet (Excel): the class's term — one sheet with every subject,
 *   the average, band, rank and result, then one sheet per subject with every
 *   score entered. All results, approved or not; each subject's status is shown.
 */
final class ResultDocuments
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BrowsershotRenderer $renderer,
        private readonly GradebookService $gradebook,
        private readonly StudentResults $results,
    ) {}

    public function reportCards(SchoolClass $class, AcademicTerm $term, ?int $studentId = null): string
    {
        $class->loadMissing('gradeLevel');
        $term->loadMissing('academicYear');
        $overview = $this->gradebook->overview($class, $term, approvedOnly: true);
        $subjects = collect($overview['subjects'])->filter(fn ($s) => $s['status'] === ResultSheet::STATUS_APPROVED)->values();

        $cards = collect($overview['students'])
            ->when($studentId, fn ($c) => $c->where('id', $studentId))
            ->map(function ($row) use ($subjects) {
                $cells = (array) $row['cells'];

                return $row + ['subjects' => $subjects->map(fn ($s) => ['title' => $s['title']] + ($cells[$s['id']] ?? ['score' => null, 'percent' => null, 'band' => null, 'passed' => null]))->all()];
            })
            ->values()->all();

        $html = view('pdf.report-cards', $this->common() + [
            'cards' => $cards,
            'class' => $class,
            'term' => $term,
            'scheme' => $overview['scheme'],
            'classSize' => count($overview['students']),
            'homeroom' => $class->homeroom_staff_id ? Staff::query()->find($class->homeroom_staff_id)?->fullName() : null,
        ])->render();

        return $this->renderer->render($html, 'A4', 12);
    }

    public function transcript(Student $student): string
    {
        $transcript = $this->results->transcript($student, approvedOnly: true);
        $scales = collect($transcript['years'])->flatMap(fn ($y) => collect($y['terms'])->pluck('scheme.max_score'))->filter()->unique()->values();

        $html = view('pdf.transcript', $this->common() + [
            'transcript' => $transcript,
            'scaleNote' => $scales->isEmpty() ? '—' : $scales->map(fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'))->implode(' / '),
        ])->render();

        return $this->renderer->render($html, 'A4', 12);
    }

    /** The class's term as an .xlsx file (binary string). */
    public function scoreSheet(SchoolClass $class, AcademicTerm $term): string
    {
        $overview = $this->gradebook->overview($class, $term);
        $tenant = $this->context->getOrFail();
        $bold = (new Style)->setFontBold();
        $head = (new Style)->setFontBold()->setBackgroundColor('F3F4F6')->setShouldWrapText();
        $title = (new Style)->setFontBold()->setFontSize(14);

        $options = new Options;
        $options->setColumnWidth(6, 1);
        $options->setColumnWidth(14, 2);
        $options->setColumnWidth(28, 3);
        $options->setColumnWidthForRange(13, 4, 40);

        $path = tempnam(sys_get_temp_dir(), 'scores');
        $writer = new Writer($options);
        $writer->openToFile($path);

        // Sheet 1: every subject.
        $writer->getCurrentSheet()->setName('Results');
        $writer->addRow(Row::fromValues([$tenant->name], $title));
        $writer->addRow(Row::fromValues([$class->name.' · '.$term->name.($overview['scheme'] ? ' · '.$overview['scheme']['name'].' (/'.$overview['scheme']['max_score'].')' : '')]));
        $writer->addRow(Row::fromValues([]));
        $subjects = collect($overview['subjects'])->all();
        $writer->addRow(Row::fromValues(array_merge(
            ['No.', 'ID', 'Student'],
            array_map(fn ($s) => $s['title'].' ('.($s['status'] ?? 'not started').')', $subjects),
            ['Average', '%', 'Band', 'Rank', 'Failed subjects', 'Result'],
        ), $head));
        $students = collect($overview['students'])->sortBy(fn ($r) => [$r['rank'] ?? 9999, $r['name']])->values();
        foreach ($students as $i => $r) {
            $cells = (array) $r['cells'];
            $writer->addRow(Row::fromValues(array_merge(
                [$i + 1, $r['code'], $r['name']],
                array_map(fn ($s) => $cells[$s['id']]['score'] ?? null, $subjects),
                [$r['score'], $r['average'], $r['band'], $r['rank'], $r['failed_subjects'], $r['passed'] === null ? '' : ($r['passed'] ? 'Pass' : 'Fail')],
            )));
        }

        // One sheet per subject: every score entered.
        foreach ($subjects as $s) {
            $sheet = $this->gradebook->sheet(null, $class, Book::query()->findOrFail($s['id']), $term);
            if ($sheet['scheme'] === null) {
                continue;
            }
            $writer->addNewSheetAndMakeItCurrent()->setName(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $s['title']), 0, 31));
            $writer->addRow(Row::fromValues([$s['title'].' · '.$class->name.' · '.$term->name.' · '.($s['status'] ?? 'not started')], $bold));
            $writer->addRow(Row::fromValues([]));
            $levels = collect($sheet['scheme']['levels'])->keyBy('id');
            $writer->addRow(Row::fromValues(array_merge(
                ['No.', 'ID', 'Student'],
                array_map(fn ($c) => $c['name'].(($c['occurrences'] ?? 1) > 1 ? ' '.$c['occurrence'] : '').(isset($c['max_score']) ? ' /'.$c['max_score'] : ''), $sheet['columns']),
                ['Score', '%', 'Band', 'Rank'],
            ), $head));
            foreach ($sheet['students'] as $i => $st) {
                $cells = (array) $st['cells'];
                $writer->addRow(Row::fromValues(array_merge(
                    [$i + 1, $st['code'], $st['name']],
                    array_map(function ($c) use ($cells, $levels) {
                        $cell = $cells[$c['key']] ?? null;
                        if ($cell === null) {
                            return null;
                        }
                        if ($cell['absent']) {
                            return 'A';
                        }

                        return isset($c['criterion_id']) ? ($levels->get($cell['level_id'])['code'] ?? null) : $cell['score'];
                    }, $sheet['columns']),
                    [$st['result']['score'] ?? null, $st['result']['percent'] ?? null, $st['result']['band'] ?? null, $st['result']['rank'] ?? null],
                )));
            }
        }

        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** @return array<string, mixed> */
    private function common(): array
    {
        $tenant = $this->context->getOrFail();

        return [
            'tenant' => $tenant,
            'logoDataUri' => PdfImageEncoder::dataUri($tenant->logoPath()),
            'stampDataUri' => PdfImageEncoder::dataUri($tenant->stampPath()),
            'khmerFontRegular' => KhmerFont::dataUri('Regular'),
            'khmerFontBold' => KhmerFont::dataUri('Bold'),
            'generatedAt' => now()->format('d-m-Y H:i'),
        ];
    }

    public static function slug(string $text): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-');

        return $slug !== '' ? $slug : 'results';
    }
}
