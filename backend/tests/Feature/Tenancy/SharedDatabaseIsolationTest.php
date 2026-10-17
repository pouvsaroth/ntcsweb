<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\LookupCategory;
use App\Models\Tenant;
use App\Support\Tenancy\SchoolConnection;
use App\Support\Tenancy\SchoolTables;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Stage 4a (docs/backoffice/stage-4-database-placement.md): several schools in
 * ONE database — exactly what the test database is, and what school_share
 * will be. Row-level security, not the app's care, keeps them apart: models,
 * raw queries and validation rules all see only the current school.
 */
class SharedDatabaseIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Tenant::factory()->create(['slug' => 'school-a']);
        $this->b = Tenant::factory()->create(['slug' => 'school-b']);
    }

    private function in(Tenant $tenant): TenantContext
    {
        $context = app(TenantContext::class);
        $context->set($tenant);

        return $context;
    }

    public function test_the_school_connection_runs_as_the_app_role_not_the_superuser(): void
    {
        $this->in($this->a);

        $who = DB::connection(SchoolConnection::NAME)->selectOne('select current_user as u, (select rolsuper from pg_roles where rolname = current_user) as super, (select rolbypassrls from pg_roles where rolname = current_user) as bypass');

        $this->assertSame(config('tenancy.database.app_role'), $who->u);
        $this->assertFalse((bool) $who->super);
        $this->assertFalse((bool) $who->bypass);
    }

    public function test_a_school_only_ever_sees_its_own_rows(): void
    {
        $this->in($this->a);
        $mine = LookupCategory::query()->create(['code' => 'shared-code', 'name' => 'A only']);
        $this->assertSame($this->a->id, (int) $mine->fresh()->tenant_id, 'tenant_id is filled by the database');

        $this->in($this->b);
        $this->assertNull(LookupCategory::query()->find($mine->id), 'model find');
        $this->assertSame(0, LookupCategory::query()->where('code', 'shared-code')->count(), 'model count');
        $this->assertSame(0, DB::connection('tenant')->table('lookup_categories')->where('id', $mine->id)->count(), 'raw query');
        $this->assertSame(0, LookupCategory::query()->whereKey($mine->id)->update(['name' => 'hijacked']), 'update across');
        $this->assertSame(0, DB::connection('tenant')->table('lookup_categories')->where('id', $mine->id)->delete(), 'raw delete across');

        $this->in($this->a);
        $this->assertSame('A only', $mine->fresh()->name);
    }

    public function test_the_same_unique_value_is_allowed_once_per_school(): void
    {
        $this->in($this->a);
        LookupCategory::query()->create(['code' => 'same', 'name' => 'A']);

        $this->in($this->b);
        LookupCategory::query()->create(['code' => 'same', 'name' => 'B']);

        $this->expectException(QueryException::class);
        LookupCategory::query()->create(['code' => 'same', 'name' => 'B twice']);
    }

    public function test_unique_and_exists_rules_only_look_at_the_current_school(): void
    {
        $this->in($this->a);
        $aRow = LookupCategory::query()->create(['code' => 'taken-in-a', 'name' => 'A']);

        $this->in($this->b);
        $this->assertTrue(Validator::make(['code' => 'taken-in-a'], ['code' => 'unique:tenant.lookup_categories,code'])->passes(), 'unique: A\'s value is free in B');
        $this->assertTrue(Validator::make(['id' => $aRow->id], ['id' => 'exists:tenant.lookup_categories,id'])->fails(), 'exists: A\'s row is not B\'s');

        $this->in($this->a);
        $this->assertTrue(Validator::make(['code' => 'taken-in-a'], ['code' => 'unique:tenant.lookup_categories,code'])->fails());
    }

    public function test_with_no_school_set_nothing_is_visible_and_nothing_can_be_written(): void
    {
        $this->in($this->a);
        LookupCategory::query()->create(['code' => 'a-row', 'name' => 'A']);

        app(TenantContext::class)->forget();
        $this->assertSame(0, LookupCategory::query()->count());

        $this->expectException(QueryException::class);
        LookupCategory::query()->create(['code' => 'orphan', 'name' => 'no school']);
    }

    public function test_a_row_cannot_be_written_for_another_school(): void
    {
        $this->in($this->b);

        $this->expectException(QueryException::class);
        DB::connection('tenant')->table('lookup_categories')->insert([
            'code' => 'smuggled', 'name' => 'x', 'sort_order' => 0, 'tenant_id' => $this->a->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_run_for_hands_the_connection_back_to_the_outer_school(): void
    {
        $this->in($this->a);
        LookupCategory::query()->create(['code' => 'outer', 'name' => 'A']);

        app(TenantContext::class)->runFor($this->b, fn () => LookupCategory::query()->count());

        $this->assertSame(1, LookupCategory::query()->where('code', 'outer')->count());
    }

    /** A school table added later without SchoolTables::secure() fails here. */
    public function test_every_school_table_is_secured(): void
    {
        $db = DB::connection();
        $tables = SchoolTables::all($db);
        $this->assertGreaterThan(100, count($tables));

        $rows = collect($db->select(<<<'SQL'
            select c.relname as t, c.relrowsecurity as rls, c.relforcerowsecurity as forced,
                   exists (select 1 from information_schema.columns col where col.table_schema = 'public' and col.table_name = c.relname and col.column_name = 'tenant_id' and col.is_nullable = 'NO') as has_column,
                   exists (select 1 from pg_policies p where p.schemaname = 'public' and p.tablename = c.relname and p.policyname = 'school_isolation') as has_policy
            from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind = 'r'
            SQL))->keyBy('t');

        foreach ($tables as $table) {
            $row = $rows[$table];
            $this->assertTrue($row->has_column && $row->rls && $row->forced && $row->has_policy, "{$table} is not secured — call SchoolTables::secure() in its migration");
        }

        $unscoped = $db->select("select indexname from pg_indexes where schemaname = 'public' and indexdef like 'CREATE UNIQUE%' and indexname not like '%_pkey' and indexdef not like '%(tenant_id,%' and tablename = any(?)", ['{'.implode(',', $tables).'}']);
        $this->assertSame([], array_map(fn ($r) => $r->indexname, $unscoped), 'unique indexes must be per school');
    }
}
