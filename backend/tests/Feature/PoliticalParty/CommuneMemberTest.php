<?php

declare(strict_types=1);

namespace Tests\Feature\PoliticalParty;

use App\Models\Commune;
use App\Models\CommuneMember;
use App\Models\CommuneSeat;
use App\Models\District;
use App\Models\Province;
use App\Models\Staff;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Political Party > Commune members: full and reserve members (HRM staff) per commune and election year. */
class CommuneMemberTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::COMMUNE_MEMBERS_VIEW, Permissions::COMMUNE_MEMBERS_CREATE, Permissions::COMMUNE_MEMBERS_UPDATE, Permissions::COMMUNE_MEMBERS_DELETE];

    private Commune $anlong;

    private Commune $norea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdminWithPermissions(self::ALL);

        $province = Province::factory()->create(['name_latin' => 'Battambang']);
        $district = District::factory()->create(['province_id' => $province->id, 'name_latin' => 'Sangkae']);
        $this->anlong = Commune::factory()->create(['district_id' => $district->id, 'name_latin' => 'Anlong Vil']);
        $this->norea = Commune::factory()->create(['district_id' => $district->id, 'name_latin' => 'Norea']);
    }

    private function add(Staff $staff, string $role = 'full', ?int $position = null, ?Commune $commune = null)
    {
        return $this->postJson('/api/v1/commune-members', [
            'election_year' => 2027,
            'commune_id' => ($commune ?? $this->anlong)->id,
            'staff_id' => $staff->id,
            'role' => $role,
            'position' => $position,
        ]);
    }

    /** @return list<string> staff names of a commune's list, in order */
    private function names(string $role, ?Commune $commune = null): array
    {
        return CommuneMember::query()->with('staff')->where('commune_id', ($commune ?? $this->anlong)->id)->where('role', $role)
            ->orderBy('position')->get()->map(fn ($m) => $m->staff->first_name)->all();
    }

    public function test_members_come_from_staff_are_numbered_and_counted_against_seats(): void
    {
        [$a, $b, $c, $d] = collect(['Dara', 'Sokha', 'Vanna', 'Bopha'])->map(fn ($n) => Staff::factory()->create(['first_name' => $n, 'national_id' => 'ID-'.$n]))->all();
        CommuneSeat::query()->create(['election_year' => 2027, 'province_id' => $this->anlong->district->province_id, 'district_id' => $this->anlong->district_id, 'commune_id' => $this->anlong->id, 'seats' => 3]);

        $first = $this->add($a)->assertCreated()->json('data');
        $this->assertSame([1, 'Battambang', 'ID-Dara'], [$first['position'], $first['province']['name_latin'], $first['staff']['national_id']]);
        $this->add($b)->assertCreated();
        $this->add($c, 'full', 1)->assertCreated(); // goes first, the others move down
        $this->add($d, 'reserve')->assertCreated();
        $this->assertSame(['Vanna', 'Dara', 'Sokha'], $this->names('full'));

        // One commune per staff member per year.
        $this->add($a, 'reserve', null, $this->norea)->assertJsonValidationErrors('staff_id');

        $res = $this->getJson("/api/v1/commune-members?election_year=2027&commune_id={$this->anlong->id}")->assertOk();
        $this->assertSame(['Vanna', 'Dara', 'Sokha', 'Bopha'], array_map(fn ($r) => explode(' ', $r['staff']['name'])[0], $res->json('data')));
        $this->assertSame([3, 1, 3], [$res->json('meta.summary.full'), $res->json('meta.summary.reserve'), $res->json('meta.summary.seats')]);
        $this->assertSame('Anlong Vil', $res->json('meta.summary.place.name_latin'));
        $this->assertCount(1, $this->getJson('/api/v1/commune-members?election_year=2027&search=sokha')->json('data'));

        // Moving Dara to reserve No. 1 closes the gap in the full list; removing renumbers too.
        $dara = CommuneMember::query()->where('staff_id', $a->id)->firstOrFail();
        $this->putJson("/api/v1/commune-members/{$dara->id}", ['election_year' => 2027, 'commune_id' => $this->anlong->id, 'staff_id' => $a->id, 'role' => 'reserve', 'position' => 1])->assertOk();
        $this->assertSame(['Vanna', 'Sokha'], $this->names('full'));
        $this->assertSame(['Dara', 'Bopha'], $this->names('reserve'));
        $this->assertSame([1, 2], CommuneMember::query()->where('role', 'full')->orderBy('position')->pluck('position')->all());

        $vanna = CommuneMember::query()->where('staff_id', $c->id)->firstOrFail();
        $this->deleteJson("/api/v1/commune-members/{$vanna->id}")->assertNoContent();
        $this->assertSame([1], CommuneMember::query()->where('role', 'full')->pluck('position')->all());

        $options = collect($this->getJson('/api/v1/commune-members/staff-options?election_year=2027')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('reserve', $options[$a->id]['taken']['role']);
        $this->assertNull($options[$c->id]['taken']);
    }

    public function test_import_adds_and_moves_members_or_saves_nothing(): void
    {
        $a = Staff::factory()->create(['employee_code' => 'E1', 'first_name' => 'Dara']);
        $b = Staff::factory()->create(['employee_code' => 'E2', 'first_name' => 'Sokha']);
        $c = Staff::factory()->create(['employee_code' => 'E3', 'first_name' => 'Vanna']);
        $this->add($a, 'reserve', null, $this->norea)->assertCreated();

        $bad = $this->import([
            ['Province', 'District', 'Commune', 'Employee code', 'Type', 'No.'],
            ['Battambang', 'Sangkae', 'Anlong Vil', 'E1', 'Full', 2],
            ['Battambang', 'Sangkae', 'Anlong Vil', 'E9', 'Full', 1],
        ]);
        $this->assertSame(3, $bad['problems'][0]['row']);
        $this->assertSame(1, CommuneMember::query()->count());

        $ok = $this->import([
            ['Province', 'District', 'Commune', 'Employee code', 'Type', 'No.'],
            ['Battambang', 'Sangkae', 'Anlong Vil', 'e1', 'Full', 2],
            ['Battambang', 'Sangkae', 'Anlong Vil', 'E2', 'ពេញសិទ្ធិ', 1],
            ['Battambang', 'Sangkae', 'Anlong Vil', 'E3', 'Reserve', null],
        ]);
        $this->assertSame([2, 1, []], [$ok['added'], $ok['moved'], $ok['problems']]);
        $this->assertSame(['Sokha', 'Dara'], $this->names('full'));
        $this->assertSame(['Vanna'], $this->names('reserve'));
        $this->assertSame([], $this->names('reserve', $this->norea));
    }

    public function test_view_only_users_cannot_change_members(): void
    {
        $staff = Staff::factory()->create();
        $this->actingAsAdminWithPermissions([Permissions::COMMUNE_MEMBERS_VIEW]);

        $this->getJson('/api/v1/commune-members')->assertOk();
        $this->add($staff)->assertForbidden();
        $this->getJson('/api/v1/commune-members/staff-options')->assertForbidden();
        $this->post('/api/v1/commune-members/import', ['election_year' => 2027], ['Accept' => 'application/json'])->assertForbidden();
    }

    /** @param  list<list<mixed>>  $rows */
    private function import(array $rows): array
    {
        $path = tempnam(sys_get_temp_dir(), 'mem').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
        $file = new UploadedFile($path, 'members.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        return $this->post('/api/v1/commune-members/import', ['election_year' => 2027, 'file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');
    }
}
