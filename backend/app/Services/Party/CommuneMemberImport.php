<?php

declare(strict_types=1);

namespace App\Services\Party;

use App\Models\CommuneMember;
use App\Models\Staff;
use App\Support\Geography\PlaceMatcher;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Political Party > Commune members: load an election year's full and
 * reserve members from Excel. Columns (English or Khmer headings): Province,
 * District, Commune, Employee code, Type (Full / Reserve), No. (optional,
 * else file order), Remark. Staff are matched by Employee code; a staff
 * member already on a list that year is moved to the row's list. Nothing
 * is saved when any row has a problem; the problems are returned instead.
 */
final class CommuneMemberImport
{
    private const HEADINGS = [
        'province' => 'province', 'ខេត្ត' => 'province', 'ខេត្ត/រាជធានី' => 'province', 'រាជធានី/ខេត្ត' => 'province',
        'district' => 'district', 'ស្រុក' => 'district', 'ស្រុក/ខណ្ឌ' => 'district', 'ស្រុក/ក្រុង/ខណ្ឌ' => 'district',
        'commune' => 'commune', 'ឃុំ' => 'commune', 'ឃុំ/សង្កាត់' => 'commune',
        'employee code' => 'code', 'staff code' => 'code', 'code' => 'code', 'អត្តលេខ' => 'code', 'លេខកូដបុគ្គលិក' => 'code',
        'type' => 'type', 'member type' => 'type', 'ប្រភេទ' => 'type', 'ប្រភេទសមាជិក' => 'type',
        'no.' => 'no', 'no' => 'no', 'order' => 'no', 'លេខរៀង' => 'no', 'ល.រ' => 'no',
        'remark' => 'remark', 'កំណត់សម្គាល់' => 'remark',
    ];

    /** Type cell (any case) => role. */
    private const TYPES = [
        'full' => CommuneMember::FULL, 'full member' => CommuneMember::FULL, 'f' => CommuneMember::FULL,
        'ពេញសិទ្ធិ' => CommuneMember::FULL, 'សមាជិកពេញសិទ្ធិ' => CommuneMember::FULL,
        'reserve' => CommuneMember::RESERVE, 'reserve member' => CommuneMember::RESERVE, 'r' => CommuneMember::RESERVE,
        'បម្រុង' => CommuneMember::RESERVE, 'សមាជិកបម្រុង' => CommuneMember::RESERVE,
    ];

    private const MAX_PROBLEMS = 50;

    public function __construct(private readonly CommuneMemberLists $lists) {}

    /**
     * @return array{added: int, moved: int, problems: list<array{sheet: string, row: int, message: string}>}
     */
    public function run(string $path, int $year): array
    {
        $places = new PlaceMatcher;
        $staff = Staff::query()->whereNotNull('employee_code')->get(['id', 'employee_code'])
            ->keyBy(fn (Staff $s) => mb_strtolower(trim((string) $s->employee_code)));
        $rows = [];
        $seen = [];
        $problems = [];
        $problem = function (string $sheet, int $row, string $message) use (&$problems) {
            if (count($problems) < self::MAX_PROBLEMS) {
                $problems[] = ['sheet' => $sheet, 'row' => $row, 'message' => $message];
            }
        };

        $reader = new Reader;
        $reader->open($path);
        foreach ($reader->getSheetIterator() as $sheet) {
            $columns = null;
            foreach ($sheet->getRowIterator() as $number => $row) {
                $cells = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row->toArray());
                if ($columns === null) {
                    $columns = $this->columns($cells);
                    if ($columns === null && array_filter($cells, fn ($v) => $v !== null && $v !== '') !== []) {
                        break;
                    }

                    continue;
                }
                $values = [];
                foreach ($columns as $index => $field) {
                    $value = $cells[$index] ?? null;
                    $values[$field] = $value === '' ? null : $value;
                }
                if (array_filter($values, fn ($v) => $v !== null) === []) {
                    continue;
                }
                $name = $sheet->getName();

                $place = $places->find($values);
                if (is_string($place)) {
                    $problem($name, $number, $place);

                    continue;
                }
                $code = mb_strtolower(trim((string) ($values['code'] ?? '')));
                $person = $code === '' ? null : $staff->get($code);
                if ($person === null) {
                    $problem($name, $number, __('party.members.staffNotFound', ['code' => (string) ($values['code'] ?? '')]));

                    continue;
                }
                if (isset($seen[$person->id])) {
                    $problem($name, $number, __('party.members.staffTwice', ['code' => (string) $values['code']]));

                    continue;
                }
                $role = self::TYPES[mb_strtolower(trim((string) ($values['type'] ?? '')))] ?? null;
                if ($role === null) {
                    $problem($name, $number, __('party.members.typeUnknown'));

                    continue;
                }
                $no = $values['no'] ?? null;
                if ($no !== null && (! is_numeric($no) || (int) $no < 1)) {
                    $problem($name, $number, __('party.members.noNumber'));

                    continue;
                }
                $seen[$person->id] = true;
                $rows[] = [
                    'election_year' => $year,
                    'province_id' => $place['province_id'],
                    'district_id' => $place['district_id'],
                    'commune_id' => $place['commune_id'],
                    'staff_id' => $person->id,
                    'role' => $role,
                    'position' => $no === null ? null : (int) $no,
                    'remark' => isset($values['remark']) ? mb_substr((string) $values['remark'], 0, 2000) : null,
                    'order' => count($rows),
                ];
            }
        }
        $reader->close();

        if ($problems === [] && $rows === []) {
            $problem('', 0, __('party.members.nothing'));
        }
        if ($problems !== []) {
            return ['added' => 0, 'moved' => 0, 'problems' => $problems];
        }

        // Each list in its numbers' order (rows without No. after, in file order).
        usort($rows, fn ($a, $b) => [$a['commune_id'], $a['role'], $a['position'] ?? PHP_INT_MAX, $a['order']]
            <=> [$b['commune_id'], $b['role'], $b['position'] ?? PHP_INT_MAX, $b['order']]);

        return DB::connection('tenant')->transaction(function () use ($rows, $year) {
            $result = ['added' => 0, 'moved' => 0, 'problems' => []];
            foreach ($rows as $row) {
                unset($row['order']);
                $member = CommuneMember::query()->firstOrNew(['election_year' => $year, 'staff_id' => $row['staff_id']]);
                $result[$member->exists ? 'moved' : 'added']++;
                $this->lists->place($member, $row);
            }

            return $result;
        });
    }

    /** An empty workbook with the headings and a sample row, in English or Khmer. */
    public function template(string $locale): string
    {
        $km = $locale === 'km';
        $headings = $km ? ['ខេត្ត', 'ស្រុក', 'ឃុំ', 'អត្តលេខ', 'ប្រភេទ', 'លេខរៀង', 'កំណត់សម្គាល់']
            : ['Province', 'District', 'Commune', 'Employee code', 'Type', 'No.', 'Remark'];

        $path = tempnam(sys_get_temp_dir(), 'members');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName($km ? 'សមាជិក' : 'Members');
        $writer->addRow(Row::fromValues($headings, (new Style)->withFontBold(true)));
        $writer->addRow(Row::fromValues(['Battambang', 'Sangkae', 'Anlong Vil', 'EMP0001', $km ? 'ពេញសិទ្ធិ' : 'Full', 1, '']));
        $writer->addRow(Row::fromValues(['Battambang', 'Sangkae', 'Anlong Vil', 'EMP0002', $km ? 'បម្រុង' : 'Reserve', 1, '']));
        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * @param  array<int, mixed>  $cells
     * @return array<int, string>|null
     */
    private function columns(array $cells): ?array
    {
        $columns = [];
        foreach ($cells as $index => $cell) {
            $key = mb_strtolower(trim(str_replace('*', '', preg_replace('/\s+/u', ' ', (string) $cell) ?? '')));
            if (isset(self::HEADINGS[$key]) && ! in_array(self::HEADINGS[$key], $columns, true)) {
                $columns[$index] = self::HEADINGS[$key];
            }
        }
        foreach (['province', 'district', 'commune', 'code', 'type'] as $needed) {
            if (! in_array($needed, $columns, true)) {
                return null;
            }
        }

        return $columns;
    }
}
