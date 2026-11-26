<?php

declare(strict_types=1);

namespace Tests\Feature\PoliticalParty;

use App\Models\Commune;
use App\Models\CommuneSeat;
use App\Models\District;
use App\Models\ElectionOffice;
use App\Models\Province;
use App\Models\Village;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Political Party > Election offices: offices per election year and each commune's council seats, searched by commune. */
class ElectionOfficeTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::ELECTION_OFFICES_VIEW, Permissions::ELECTION_OFFICES_CREATE, Permissions::ELECTION_OFFICES_UPDATE, Permissions::ELECTION_OFFICES_DELETE];

    private Commune $anlong;

    private Commune $other;

    private Village $village;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdminWithPermissions(self::ALL);

        $province = Province::factory()->create(['name_km' => 'បាត់ដំបង', 'name_latin' => 'Battambang']);
        $district = District::factory()->create(['province_id' => $province->id, 'name_latin' => 'Sangkae']);
        $this->anlong = Commune::factory()->create(['district_id' => $district->id, 'name_km' => 'អន្លង់វិល', 'name_latin' => 'Anlong Vil']);
        $this->other = Commune::factory()->create(['district_id' => $district->id, 'name_latin' => 'Norea']);
        $this->village = Village::factory()->create(['commune_id' => $this->anlong->id, 'name_latin' => 'Chrab Veal']);
    }

    public function test_a_commune_shows_its_seats_offices_and_voters(): void
    {
        $this->postJson('/api/v1/election-offices', ['election_year' => 2027, 'code' => '0101', 'name' => 'Primary school', 'commune_id' => $this->anlong->id, 'village_id' => $this->village->id, 'voters' => 600])->assertCreated();
        $this->postJson('/api/v1/election-offices', ['election_year' => 2027, 'code' => '0102', 'name' => 'Pagoda', 'commune_id' => $this->anlong->id, 'voters' => 400])->assertCreated();
        $this->postJson('/api/v1/election-offices', ['election_year' => 2027, 'code' => '0201', 'name' => 'Norea school', 'commune_id' => $this->other->id, 'voters' => 300])->assertCreated();
        $this->putJson('/api/v1/election-seats', ['election_year' => 2027, 'commune_id' => $this->anlong->id, 'seats' => 9])->assertOk();
        $this->putJson('/api/v1/election-seats', ['election_year' => 2027, 'commune_id' => $this->anlong->id, 'seats' => 11])->assertOk();

        $res = $this->getJson("/api/v1/election-offices?commune_id={$this->anlong->id}")->assertOk();
        $this->assertSame(['0101', '0102'], array_column($res->json('data'), 'code'));
        $this->assertSame('Battambang', $res->json('data.0.province.name_latin'));
        $this->assertSame('Chrab Veal', $res->json('data.0.village.name_latin'));
        $this->assertSame('Anlong Vil', $res->json('meta.summary.place.name_latin'));
        $this->assertSame([2027, 2, 1000, 11, 1], array_values(collect($res->json('meta.summary'))->except('place')->all()));

        $seats = $this->getJson('/api/v1/election-seats?election_year=2027')->assertOk()->json('data');
        $this->assertCount(1, $seats);
        $this->assertSame([11, 2, 1000], [$seats[0]['seats'], $seats[0]['offices'], $seats[0]['voters']]);

        $this->assertSame([2027], $this->getJson('/api/v1/election-offices/years')->json('data'));

        // The same code may not be used twice in a year; the village must be in the commune.
        $this->postJson('/api/v1/election-offices', ['election_year' => 2027, 'code' => '0101', 'name' => 'X', 'commune_id' => $this->anlong->id])->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/election-offices', ['election_year' => 2027, 'code' => '0301', 'name' => 'X', 'commune_id' => $this->other->id, 'village_id' => $this->village->id])->assertJsonValidationErrors('village_id');
    }

    public function test_import_adds_and_changes_offices_and_seats(): void
    {
        ElectionOffice::factory()->create(['election_year' => 2027, 'code' => '0101', 'name' => 'Old name', 'province_id' => $this->anlong->district->province_id, 'district_id' => $this->anlong->district_id, 'commune_id' => $this->anlong->id]);

        $file = $this->workbook([
            ['Province', 'District', 'Commune', 'Village', 'Office code', 'Office name', 'Voters'],
            ['battambang', 'Sangkae', 'អន្លង់វិល', 'Chrab Veal', '0101', 'Primary school', 650],
            ['Battambang', 'Sangkae', 'Norea', null, '0201', 'Norea school', 300],
        ], [
            ['Province', 'District', 'Commune', 'Seats'],
            ['Battambang', 'Sangkae', 'Anlong Vil', 9],
        ]);

        $result = $this->post('/api/v1/election-offices/import', ['election_year' => 2027, 'file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame([1, 1, 1, 0, []], [$result['offices_added'], $result['offices_changed'], $result['seats_added'], $result['seats_changed'], $result['problems']]);
        $this->assertSame('Primary school', ElectionOffice::query()->where('code', '0101')->value('name'));
        $this->assertSame($this->village->id, ElectionOffice::query()->where('code', '0101')->value('village_id'));
        $this->assertSame(9, CommuneSeat::query()->where('commune_id', $this->anlong->id)->value('seats'));
    }

    public function test_import_saves_nothing_when_a_row_has_a_problem(): void
    {
        $file = $this->workbook([
            ['Province', 'District', 'Commune', 'Office code', 'Office name'],
            ['Battambang', 'Sangkae', 'Anlong Vil', '0101', 'Primary school'],
            ['Battambang', 'Sangkae', 'Nowhere', '0102', 'Pagoda'],
        ]);

        $result = $this->post('/api/v1/election-offices/import', ['election_year' => 2027, 'file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertCount(1, $result['problems']);
        $this->assertSame(3, $result['problems'][0]['row']);
        $this->assertSame(0, ElectionOffice::query()->count());
    }

    public function test_the_excel_template_downloads(): void
    {
        $this->get('/api/v1/election-offices/template')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_view_only_users_cannot_change_anything(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ELECTION_OFFICES_VIEW]);

        $this->getJson('/api/v1/election-offices')->assertOk();
        $this->postJson('/api/v1/election-offices', ['election_year' => 2027, 'code' => '1', 'name' => 'X', 'commune_id' => $this->anlong->id])->assertForbidden();
        $this->putJson('/api/v1/election-seats', ['election_year' => 2027, 'commune_id' => $this->anlong->id, 'seats' => 9])->assertForbidden();
        $this->post('/api/v1/election-offices/import', ['election_year' => 2027], ['Accept' => 'application/json'])->assertForbidden();
    }

    /** @param  list<list<mixed>>  ...$sheets */
    private function workbook(array ...$sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'elx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($sheets as $i => $rows) {
            if ($i > 0) {
                $writer->addNewSheetAndMakeItCurrent();
            }
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }
        $writer->close();

        return new UploadedFile($path, 'offices.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
