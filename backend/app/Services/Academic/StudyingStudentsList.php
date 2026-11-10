<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\KhmerFont;
use App\Services\Pdf\PdfImageEncoder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Students > Export: every student studying in a class right now (an
 * enrollment with status Studying), narrowed by the Students page's search,
 * class and academic year — with only the columns picked, and the total
 * and female count on top. Excel and PDF here; the image is drawn in the
 * browser from the same rows.
 */
final class StudyingStudentsList
{
    /** Every column that can be picked, in display order (No. is always first). */
    public const COLUMNS = [
        'code', 'name', 'english_name', 'gender', 'date_of_birth', 'phone',
        'class', 'course', 'enrolled_at', 'address', 'guardian',
    ];

    /** Picked when none are given. */
    public const DEFAULT_COLUMNS = ['code', 'name', 'gender', 'class', 'phone'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly BrowsershotRenderer $renderer,
    ) {}

    /**
     * @param  array{search?: string|null, class_id?: int|null, academic_year_id?: int|null}  $filters
     * @param  list<string>  $columns
     * @return array{columns: list<string>, total: int, female: int, rows: list<array<string, string>>}
     */
    public function build(array $filters, array $columns): array
    {
        $classId = $filters['class_id'] ?? null;
        $yearId = $filters['academic_year_id'] ?? null;
        // The enrollments that count: Studying, in the picked class / year.
        $studying = fn ($query) => $query->active()
            ->when($classId, fn ($q) => $q->where('class_id', $classId))
            ->when($yearId, fn ($q) => $q->whereHas('schoolClass', fn ($c) => $c->where('academic_year_id', $yearId)));

        $search = trim((string) ($filters['search'] ?? ''));
        $students = Student::query()
            ->whereHas('enrollments', $studying)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('first_name', 'ilike', "%{$search}%")
                ->orWhere('last_name', 'ilike', "%{$search}%")
                ->orWhere('english_name', 'ilike', "%{$search}%")
                ->orWhere('student_code', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")))
            ->with([
                'enrollments' => fn ($query) => $studying($query)->with(['schoolClass', 'coursePackage']),
                'guardians',
                'village.commune.district.province',
            ])
            ->get();

        $rows = $students
            ->map(function (Student $student) use ($columns) {
                /** @var Collection<int, Enrollment> $enrollments */
                $enrollments = $student->enrollments->sortBy(fn (Enrollment $e) => $e->schoolClass?->name ?? '')->values();
                $values = [
                    'code' => (string) $student->student_code,
                    'name' => $student->fullName(),
                    'english_name' => (string) ($student->english_name ?? ''),
                    'gender' => $this->genderLabel($student->gender),
                    'date_of_birth' => $student->date_of_birth?->format('d-m-Y') ?? '',
                    'phone' => (string) ($student->phone ?? ''),
                    'class' => $enrollments->map(fn (Enrollment $e) => $e->schoolClass?->name)->filter()->unique()->implode(', '),
                    'course' => $enrollments->map(fn (Enrollment $e) => $e->coursePackage?->name)->filter()->unique()->implode(', '),
                    'enrolled_at' => $enrollments->map(fn (Enrollment $e) => $e->enrolled_at?->format('d-m-Y'))->filter()->unique()->implode(', '),
                    'address' => (string) ($student->fullAddress() ?? ''),
                    'guardian' => $student->guardians
                        ->map(fn (StudentGuardian $g) => trim($g->guardian_name.($g->phone ? ' ('.$g->phone.')' : '')))
                        ->filter()->implode(', '),
                ];

                return ['_sort' => [$values['class'], $values['name']], '_female' => $this->isFemale($student->gender)]
                    + array_intersect_key($values, array_flip($columns));
            })
            ->sortBy('_sort')
            ->values();

        return [
            'columns' => $columns,
            'total' => $rows->count(),
            'female' => $rows->where('_female', true)->count(),
            'rows' => $rows->map(fn (array $row) => array_diff_key($row, ['_sort' => 0, '_female' => 0]))->all(),
        ];
    }

    /**
     * The picked columns, kept in display order; unknown ones dropped.
     *
     * @return list<string>
     */
    public static function pickColumns(?string $csv): array
    {
        $picked = array_filter(array_map('trim', explode(',', (string) $csv)));
        $columns = array_values(array_intersect(self::COLUMNS, $picked));

        return $columns === [] ? self::DEFAULT_COLUMNS : $columns;
    }

    /** The list as an .xlsx file (binary string). */
    public function excel(array $list): string
    {
        $tenant = $this->context->getOrFail();
        $columns = $list['columns'];

        $options = new Options;
        $options->setColumnWidth(6, 1);
        foreach ($columns as $i => $column) {
            $options->setColumnWidth($this->excelWidth($column), $i + 2);
        }

        $path = tempnam(sys_get_temp_dir(), 'students');
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Students');

        $writer->addRow(Row::fromValues([$tenant->name], (new Style)->setFontBold()->setFontSize(14)));
        $writer->addRow(Row::fromValues([__('student_list.title')], (new Style)->setFontBold()));
        $writer->addRow(Row::fromValues([$this->totalsLine($list)], (new Style)->setFontBold()));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(
            array_merge([__('student_list.no')], array_map(fn (string $c) => __('student_list.columns.'.$c), $columns)),
            (new Style)->setFontBold()->setBackgroundColor('F3F4F6'),
        ));

        $wrap = (new Style)->setShouldWrapText();
        foreach ($list['rows'] as $i => $row) {
            $writer->addRow(Row::fromValues(array_merge([$i + 1], array_map(fn (string $c) => $row[$c] ?? '', $columns)), $wrap));
        }

        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** The list as an A4 PDF — landscape when more than six columns are picked. */
    public function pdf(array $list): string
    {
        $tenant = $this->context->getOrFail();

        $html = view('pdf.student-list', [
            'tenant' => $tenant,
            'logoDataUri' => PdfImageEncoder::dataUri($tenant->logoPath()),
            'khmerFontRegular' => KhmerFont::dataUri('Regular'),
            'khmerFontBold' => KhmerFont::dataUri('Bold'),
            'generatedAt' => now($tenant->timezone ?: 'Asia/Phnom_Penh')->format('d-m-Y H:i'),
            'list' => $list,
            'totalsLine' => $this->totalsLine($list),
        ])->render();

        return $this->renderer->render($html, 'A4', 10, landscape: count($list['columns']) > 6);
    }

    private function totalsLine(array $list): string
    {
        return __('student_list.total').': '.$list['total'].'    '.__('student_list.female').': '.$list['female'];
    }

    private function isFemale(?string $gender): bool
    {
        return in_array(strtolower(trim((string) $gender)), ['female', 'f'], true);
    }

    private function genderLabel(?string $gender): string
    {
        return match (strtolower(trim((string) $gender))) {
            'female', 'f' => __('student_list.gender_female'),
            'male', 'm' => __('student_list.gender_male'),
            '' => '',
            default => (string) $gender,
        };
    }

    private function excelWidth(string $column): int
    {
        return match ($column) {
            'code', 'gender', 'date_of_birth', 'enrolled_at' => 12,
            'phone' => 14,
            'name', 'english_name' => 26,
            'class', 'course' => 28,
            default => 40,
        };
    }
}
