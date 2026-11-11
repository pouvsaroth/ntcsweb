<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\DataOps\PgDump;
use App\Platform\Models\Backup;
use App\Platform\Services\SchoolDatabase;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakePgDump;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Data ops > Tables, against two small linked tables made inside the test's
 * own transaction (rolled back afterwards): bt_classes <- bt_pupils.
 */
class PlatformTableDataTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private string $central;

    private FakePgDump $pgDump;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        config(['platform.backup_copy_disk' => null]);

        // The browser's own connection would not see this test's open
        // transaction, so run it on the same connection.
        $fake = new class extends FakeSchoolDatabase
        {
            public function usingDatabase(string $database, callable $callback): mixed
            {
                return $callback(DB::connection(config('tenancy.database.central_connection')));
            }
        };
        $this->central = (string) config('database.connections.pgsql.database');
        $fake->existing = [$this->central];
        $this->app->instance(SchoolDatabase::class, $fake);
        $this->pgDump = new FakePgDump;
        $this->app->instance(PgDump::class, $this->pgDump);

        /** @var Connection $db */
        $db = DB::connection(config('tenancy.database.central_connection'));
        $db->statement('create table bt_classes (id bigserial primary key, name varchar(50) not null, note text)');
        $db->statement('create table bt_pupils (id bigserial primary key, class_id bigint not null references bt_classes(id) on delete cascade, name varchar(50) not null, api_token varchar(60))');
        $db->table('bt_classes')->insert([['name' => 'Morning A', 'note' => null], ['name' => 'Evening B', 'note' => 'late']]);
        $morning = $db->table('bt_classes')->where('name', 'Morning A')->value('id');
        $db->table('bt_pupils')->insert([
            ['class_id' => $morning, 'name' => 'Sok Dara', 'api_token' => 'secret-1'],
            ['class_id' => $morning, 'name' => 'Chan Lina', 'api_token' => null],
        ]);
    }

    private function rows(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/platform/data/rows?'.http_build_query(['database' => $this->central, ...$query]));
    }

    public function test_rows_are_listed_with_columns_and_filtered_by_all_conditions(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.data.read']));

        $all = $this->rows(['table' => 'bt_classes'])->assertOk();
        $this->assertSame(2, $all->json('data.total'));
        $this->assertSame(['id', 'name', 'note'], array_column($all->json('data.columns'), 'name'));
        $this->assertTrue($all->json('data.columns.0.primary'));
        $this->assertFalse($all->json('data.columns.0.editable'));

        $one = $this->rows(['table' => 'bt_classes', 'conditions' => [
            ['column' => 'name', 'operator' => 'contains', 'value' => 'ing'],
            ['column' => 'note', 'operator' => 'null'],
        ]])->assertOk();
        $this->assertSame(1, $one->json('data.total'));
        $this->assertSame('Morning A', $one->json('data.rows.0.name'));

        // A typed comparison: the value is cast to the column's type.
        $this->rows(['table' => 'bt_classes', 'conditions' => [['column' => 'id', 'operator' => 'gt', 'value' => '0']]])
            ->assertOk()->assertJsonPath('data.total', 2);
        // A bad value for the type is a clear 422, not a 500.
        $this->rows(['table' => 'bt_classes', 'conditions' => [['column' => 'id', 'operator' => 'eq', 'value' => 'abc']]])
            ->assertUnprocessable()->assertJsonValidationErrors('conditions');
    }

    public function test_secret_columns_are_masked_and_cannot_be_filtered_or_changed(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.data.read', 'platform.data.edit']));

        $res = $this->rows(['table' => 'bt_pupils', 'sort' => 'name'])->assertOk();
        $this->assertSame([null, '••••'], array_column($res->json('data.rows'), 'api_token'));

        $this->rows(['table' => 'bt_pupils', 'conditions' => [['column' => 'api_token', 'operator' => 'eq', 'value' => 'secret-1']]])
            ->assertUnprocessable();

        $id = (string) DB::table('bt_pupils')->where('name', 'Sok Dara')->value('id');
        $this->patchJson('/api/v1/platform/data/rows', ['database' => $this->central, 'table' => 'bt_pupils', 'key' => ['id' => $id], 'changes' => ['api_token' => 'x']])
            ->assertUnprocessable();
    }

    public function test_one_row_is_changed_by_its_key_and_audited(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.data.read', 'platform.data.edit']));
        $id = (string) DB::table('bt_classes')->where('name', 'Evening B')->value('id');

        $this->patchJson('/api/v1/platform/data/rows', [
            'database' => $this->central, 'table' => 'bt_classes', 'key' => ['id' => $id],
            'changes' => ['name' => 'Evening C', 'note' => null],
        ])->assertOk()->assertJsonPath('data.row.name', 'Evening C');

        $this->assertDatabaseHas('bt_classes', ['id' => $id, 'name' => 'Evening C', 'note' => null]);
        $this->assertDatabaseHas('bt_classes', ['name' => 'Morning A']);
        $log = DB::table('bo_audit_logs')->where('action', 'data.row.updated')->sole();
        $this->assertSame(['name' => 'Evening B', 'note' => 'late'], json_decode($log->old_values, true));

        // A NOT NULL column can't be emptied; the key column can't be changed.
        $this->patchJson('/api/v1/platform/data/rows', ['database' => $this->central, 'table' => 'bt_classes', 'key' => ['id' => $id], 'changes' => ['name' => null]])
            ->assertUnprocessable();
        $this->patchJson('/api/v1/platform/data/rows', ['database' => $this->central, 'table' => 'bt_classes', 'key' => ['id' => $id], 'changes' => ['id' => '999']])
            ->assertUnprocessable();
    }

    public function test_relationships_show_both_directions_with_row_counts(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.data.read']));

        $res = $this->getJson('/api/v1/platform/data/relationships?database='.$this->central.'&table=bt_classes')->assertOk();
        $this->assertSame(2, $res->json('data.rows'));
        $this->assertSame('bt_pupils', $res->json('data.referenced_by.0.from_table'));
        $this->assertSame(['class_id'], $res->json('data.referenced_by.0.from_columns'));
        $this->assertSame('cascade', $res->json('data.referenced_by.0.on_delete'));
        $this->assertSame(2, $res->json('data.referenced_by.0.rows'));
        $this->assertSame([['table' => 'bt_pupils', 'rows' => 2]], $res->json('data.cascade'));

        $pupils = $this->getJson('/api/v1/platform/data/relationships?database='.$this->central.'&table=bt_pupils')->assertOk();
        $this->assertSame('bt_classes', $pupils->json('data.references.0.to_table'));
    }

    public function test_clearing_needs_the_typed_name_and_cascade_when_others_point_to_it(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.data.read', 'platform.data.edit']));
        $body = ['database' => $this->central, 'table' => 'bt_classes', 'backup_first' => false];

        $this->postJson('/api/v1/platform/data/clear', [...$body, 'confirm' => 'bt_class'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->postJson('/api/v1/platform/data/clear', [...$body, 'confirm' => 'bt_classes'])->assertUnprocessable()->assertJsonValidationErrors('cascade');
        $this->assertSame(2, DB::table('bt_classes')->count());

        $this->postJson('/api/v1/platform/data/clear', [...$body, 'confirm' => 'bt_classes', 'cascade' => true])
            ->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.cleared', ['bt_classes', 'bt_pupils']);
        $this->assertSame(0, DB::table('bt_classes')->count());
        $this->assertSame(0, DB::table('bt_pupils')->count());
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'data.table.cleared']);
    }

    public function test_clearing_backs_up_every_table_it_empties_first(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.data.read', 'platform.data.edit']));

        $this->postJson('/api/v1/platform/data/clear', ['database' => $this->central, 'table' => 'bt_classes', 'confirm' => 'bt_classes', 'cascade' => true])
            ->assertOk();

        $backup = Backup::query()->sole();
        $this->assertSame('ok', $backup->status);
        $this->assertSame([['name' => 'bt_classes', 'mode' => 'full'], ['name' => 'bt_pupils', 'mode' => 'full']], $this->pgDump->tables[$this->central]);
    }

    public function test_a_failed_backup_stops_the_clear(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.data.read', 'platform.data.edit']));
        $this->pgDump->failFor[$this->central] = 'pg_dump: error: disk full';

        $token = $this->postJson('/api/v1/platform/data/clear', ['database' => $this->central, 'table' => 'bt_pupils', 'confirm' => 'bt_pupils'])
            ->assertOk()->assertJsonPath('data.status', 'failed')->json('data.token');
        $this->assertStringContainsString('disk full', $this->getJson('/api/v1/platform/data/clear/'.$token)->assertOk()->json('data.error'));
        $this->assertSame(2, DB::table('bt_pupils')->count());
    }

    public function test_deleting_a_table_needs_the_typed_name_and_removes_only_the_links_of_tables_pointing_to_it(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.data.read', 'platform.data.edit']));
        $body = ['database' => $this->central, 'table' => 'bt_classes'];
        $exists = fn (string $t) => DB::selectOne('select to_regclass(?) as r', ["public.{$t}"])->r !== null;

        $this->deleteJson('/api/v1/platform/data/table', [...$body, 'confirm' => 'bt_class'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->deleteJson('/api/v1/platform/data/table', [...$body, 'confirm' => 'bt_classes'])->assertUnprocessable()->assertJsonValidationErrors('cascade');
        $this->assertTrue($exists('bt_classes'));

        $this->deleteJson('/api/v1/platform/data/table', [...$body, 'confirm' => 'bt_classes', 'cascade' => true])
            ->assertOk()->assertJsonPath('data.links_removed.0', fn ($v) => str_starts_with($v, 'bt_pupils.'));
        $this->assertFalse($exists('bt_classes'));
        // The table that pointed to it stays, with its rows.
        $this->assertSame(2, DB::table('bt_pupils')->count());
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'data.table.dropped']);

        // Nothing points to bt_pupils: no tick needed.
        $this->deleteJson('/api/v1/platform/data/table', ['database' => $this->central, 'table' => 'bt_pupils', 'confirm' => 'bt_pupils'])->assertOk();
        $this->assertFalse($exists('bt_pupils'));
        $this->deleteJson('/api/v1/platform/data/table', ['database' => $this->central, 'table' => 'bt_pupils', 'confirm' => 'bt_pupils'])->assertJsonValidationErrors('table');
    }

    public function test_reading_alone_cannot_change_or_clear(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.data.read']));
        $id = (string) DB::table('bt_classes')->value('id');

        $this->patchJson('/api/v1/platform/data/rows', ['database' => $this->central, 'table' => 'bt_classes', 'key' => ['id' => $id], 'changes' => ['name' => 'x']])->assertNotFound();
        $this->postJson('/api/v1/platform/data/clear', ['database' => $this->central, 'table' => 'bt_pupils', 'confirm' => 'bt_pupils'])->assertNotFound();
        $this->deleteJson('/api/v1/platform/data/table', ['database' => $this->central, 'table' => 'bt_pupils', 'confirm' => 'bt_pupils'])->assertNotFound();
    }
}
