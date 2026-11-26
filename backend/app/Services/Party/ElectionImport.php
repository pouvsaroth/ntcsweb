<?php

declare(strict_types=1);

namespace App\Services\Party;

use App\Models\Commune;
use App\Models\CommuneSeat;
use App\Models\District;
use App\Models\ElectionOffice;
use App\Models\Province;
use App\Models\Village;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Political Party > Election offices: load an election year's offices and
 * communes' seats from Excel (e.g. the NEC list). Each sheet is read by its
 * heading row: one with an office code column is an offices sheet, one
 * with a seats column (and no office code) is a seats sheet. Places are
 * matched by Khmer name, Latin name or code, each inside the one above it.
 * Offices are matched on (year, code) and seats on (year, commune): new
 * ones are added, existing ones changed. Nothing is saved when any row has
 * a problem; the problems are returned instead.
 */
final class ElectionImport
{
    /** Heading (English or Khmer, any case) => field. */
    private const HEADINGS = [
        'province' => 'province', 'ខេត្ត' => 'province', 'ខេត្ត/រាជធានី' => 'province', 'រាជធានី/ខេត្ត' => 'province',
        'district' => 'district', 'ស្រុក' => 'district', 'ស្រុក/ខណ្ឌ' => 'district', 'ស្រុក/ក្រុង/ខណ្ឌ' => 'district',
        'commune' => 'commune', 'ឃុំ' => 'commune', 'ឃុំ/សង្កាត់' => 'commune',
        'village' => 'village', 'ភូមិ' => 'village',
        'office code' => 'code', 'code' => 'code', 'លេខការិយាល័យ' => 'code', 'លេខកូដ' => 'code',
        'office name' => 'name', 'name' => 'name', 'ឈ្មោះការិយាល័យ' => 'name',
        'location' => 'location', 'ទីតាំង' => 'location',
        'voters' => 'voters', 'ចំនួនអ្នកបោះឆ្នោត' => 'voters',
        'seats' => 'seats', 'ចំនួនអាសនៈ' => 'seats', 'អាសនៈ' => 'seats',
    ];

    private const MAX_PROBLEMS = 50;

    /** @var Collection<int, Province>|null */
    private ?Collection $provinces = null;

    /** @var array<string, Collection<int, mixed>> */
    private array $children = [];

    /**
     * @return array{offices_added: int, offices_changed: int, seats_added: int, seats_changed: int, problems: list<array{sheet: string, row: int, message: string}>}
     */
    public function run(string $path, int $year): array
    {
        $offices = [];
        $seats = [];
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
                        break; // Not a sheet this import reads.
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

                $place = $this->place($values);
                if (is_string($place)) {
                    $problem($name, $number, $place);

                    continue;
                }

                if (in_array('code', $columns, true)) {
                    $code = $values['code'] === null ? '' : trim((string) $values['code']);
                    $officeName = trim((string) ($values['name'] ?? ''));
                    $voters = $values['voters'] ?? null;
                    if ($code === '' || $officeName === '') {
                        $problem($name, $number, __('party.import.officeNeeds'));
                    } elseif ($voters !== null && (! is_numeric($voters) || (int) $voters < 0)) {
                        $problem($name, $number, __('party.import.votersNumber'));
                    } elseif (isset($offices[$code])) {
                        $problem($name, $number, __('party.import.codeTwice', ['code' => $code]));
                    } else {
                        $offices[$code] = [
                            'election_year' => $year, 'code' => mb_substr($code, 0, 30), 'name' => mb_substr($officeName, 0, 255),
                            ...$place,
                            'location' => isset($values['location']) ? mb_substr((string) $values['location'], 0, 255) : null,
                            'voters' => $voters === null ? null : (int) $voters,
                        ];
                    }
                }
                if (in_array('seats', $columns, true) && ($values['seats'] ?? null) !== null) {
                    $count = $values['seats'];
                    if (! is_numeric($count) || (int) $count < 1 || (int) $count > 500) {
                        $problem($name, $number, __('party.import.seatsNumber'));
                    } else {
                        $previous = $seats[$place['commune_id']] ?? null;
                        if ($previous !== null && $previous['seats'] !== (int) $count) {
                            $problem($name, $number, __('party.import.seatsDiffer'));
                        }
                        $seats[$place['commune_id']] = [
                            'election_year' => $year, 'province_id' => $place['province_id'], 'district_id' => $place['district_id'],
                            'commune_id' => $place['commune_id'], 'seats' => (int) $count,
                        ];
                    }
                }
            }
        }
        $reader->close();

        if ($problems === [] && $offices === [] && $seats === []) {
            $problem('', 0, __('party.import.nothing'));
        }
        if ($problems !== []) {
            return ['offices_added' => 0, 'offices_changed' => 0, 'seats_added' => 0, 'seats_changed' => 0, 'problems' => $problems];
        }

        return DB::connection('tenant')->transaction(function () use ($offices, $seats, $year) {
            $result = ['offices_added' => 0, 'offices_changed' => 0, 'seats_added' => 0, 'seats_changed' => 0, 'problems' => []];
            foreach ($offices as $data) {
                $office = ElectionOffice::query()->firstOrNew(['election_year' => $year, 'code' => $data['code']]);
                $result[$office->exists ? 'offices_changed' : 'offices_added']++;
                $office->fill($data)->save();
            }
            foreach ($seats as $data) {
                $seat = CommuneSeat::query()->firstOrNew(['election_year' => $year, 'commune_id' => $data['commune_id']]);
                $result[$seat->exists ? 'seats_changed' : 'seats_added']++;
                $seat->fill($data)->save();
            }

            return $result;
        });
    }

    /** An empty workbook with both sheets' headings, in English or Khmer. */
    public function template(string $locale): string
    {
        $km = $locale === 'km';
        $offices = $km ? ['ខេត្ត', 'ស្រុក', 'ឃុំ', 'ភូមិ', 'លេខការិយាល័យ', 'ឈ្មោះការិយាល័យ', 'ទីតាំង', 'ចំនួនអ្នកបោះឆ្នោត']
            : ['Province', 'District', 'Commune', 'Village', 'Office code', 'Office name', 'Location', 'Voters'];
        $seats = $km ? ['ខេត្ត', 'ស្រុក', 'ឃុំ', 'ចំនួនអាសនៈ'] : ['Province', 'District', 'Commune', 'Seats'];
        $bold = (new Style)->withFontBold(true);

        $path = tempnam(sys_get_temp_dir(), 'election');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName($km ? 'ការិយាល័យ' : 'Offices');
        $writer->addRow(Row::fromValues($offices, $bold));
        $writer->addRow(Row::fromValues(['Battambang', 'Sangkae', 'Anlong Vil', 'Chrab Veal', '0101', $km ? 'សាលាបឋមសិក្សា' : 'Primary school', '', 650]));
        $writer->addNewSheetAndMakeItCurrent()->setName($km ? 'អាសនៈ' : 'Seats');
        $writer->addRow(Row::fromValues($seats, $bold));
        $writer->addRow(Row::fromValues(['Battambang', 'Sangkae', 'Anlong Vil', 9]));
        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * Column index => field, or null when the row is not a heading row this import knows.
     *
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
        $has = fn (string $f) => in_array($f, $columns, true);

        return $has('province') && $has('district') && $has('commune') && ($has('code') || $has('seats')) ? $columns : null;
    }

    /**
     * The row's place ids, or a message saying which name was not found.
     *
     * @param  array<string, mixed>  $values
     * @return array{province_id: int, district_id: int, commune_id: int, village_id: int|null}|string
     */
    private function place(array $values): array|string
    {
        $this->provinces ??= Province::query()->get(['id', 'code', 'name_km', 'name_latin']);

        $province = $this->match($this->provinces, $values['province'] ?? null);
        if ($province === null) {
            return __('party.import.notFound', ['level' => __('party.levels.province'), 'name' => (string) ($values['province'] ?? '')]);
        }
        $district = $this->match($this->childrenOf(District::class, 'province_id', $province->id), $values['district'] ?? null);
        if ($district === null) {
            return __('party.import.notFound', ['level' => __('party.levels.district'), 'name' => (string) ($values['district'] ?? '')]);
        }
        $commune = $this->match($this->childrenOf(Commune::class, 'district_id', $district->id), $values['commune'] ?? null);
        if ($commune === null) {
            return __('party.import.notFound', ['level' => __('party.levels.commune'), 'name' => (string) ($values['commune'] ?? '')]);
        }
        $village = null;
        if (($values['village'] ?? null) !== null) {
            $village = $this->match($this->childrenOf(Village::class, 'commune_id', $commune->id), $values['village']);
            if ($village === null) {
                return __('party.import.notFound', ['level' => __('party.levels.village'), 'name' => (string) $values['village']]);
            }
        }

        return ['province_id' => $province->id, 'district_id' => $district->id, 'commune_id' => $commune->id, 'village_id' => $village?->id];
    }

    /**
     * @param  class-string<Model>  $model
     * @return Collection<int, mixed>
     */
    private function childrenOf(string $model, string $parentKey, int $parentId): Collection
    {
        return $this->children[$model.':'.$parentId] ??= $model::query()->where($parentKey, $parentId)->get(['id', 'code', 'name_km', 'name_latin']);
    }

    /** The place whose code, Khmer name or Latin name is `$value` (any case, extra spaces ignored). */
    private function match(Collection $places, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $wanted = $this->normal((string) $value);

        return $places->first(fn ($p) => $this->normal((string) $p->code) === $wanted
            || $this->normal((string) $p->name_km) === $wanted
            || $this->normal((string) $p->name_latin) === $wanted);
    }

    private function normal(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    }
}
