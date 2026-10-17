<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\LookupCategory;
use App\Models\Tenant;
use App\Platform\DataOps\SchoolExporter;
use App\Platform\DataOps\SchoolImporter;
use App\Platform\Jobs\MoveSchool;
use App\Platform\Models\SchoolMove;
use App\Platform\Models\SchoolPlacement;
use App\Platform\Services\SchoolDatabase;
use App\Support\Tenancy\SchoolTables;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 4d: moving a school between placements. The whole move (clone,
 * copy, verify, switch, rollback) was proven against real databases locally —
 * see docs/backoffice/stage-4-database-placement.md. Here: the copy itself
 * round-trips, and only allowed moves start.
 */
class PlatformMoveTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SchoolDatabase::class, new FakeSchoolDatabase);
    }

    private function school(string $slug, string $mode = 'shared'): Tenant
    {
        $tenant = Tenant::factory()->create(['slug' => $slug]);
        SchoolPlacement::query()->create(['tenant_id' => $tenant->id, 'mode' => $mode, 'database' => $mode === 'shared' ? 'school_share' : 'tn_'.$slug]);

        return $tenant;
    }

    public function test_a_school_round_trips_through_export_and_import_with_its_ids(): void
    {
        $a = $this->school('share-a');
        $b = $this->school('share-b');
        $context = app(TenantContext::class);
        $context->runFor($a, function () {
            $parent = LookupCategory::query()->create(['code' => 'a-1', 'name' => 'A one']);
            LookupCategory::query()->create(['code' => 'a-2', 'name' => 'A two']);
        });
        $context->runFor($b, fn () => LookupCategory::query()->create(['code' => 'b-1', 'name' => 'B one']));
        $before = $context->runFor($a, fn () => LookupCategory::query()->orderBy('id')->get(['id', 'code'])->toArray());

        $zip = tempnam(sys_get_temp_dir(), 'export').'.zip';
        $exported = app(SchoolExporter::class)->export($a, $zip);

        // In tests every school shares one database and its rows sit in the
        // school connection's own test transaction — work through that.
        $db = DB::connection('tenant');

        // Gone from its place …
        $context->runFor($a, fn () => app(SchoolImporter::class)->deleteAll($db, $a->id));
        $this->assertSame(0, $context->runFor($a, fn () => LookupCategory::query()->count()));
        $this->assertSame(1, $context->runFor($b, fn () => LookupCategory::query()->where('code', 'b-1')->count()), 'the other school is untouched');

        // … and back, ids and all.
        $written = $context->runFor($a, fn () => app(SchoolImporter::class)->import($zip, $db));
        @unlink($zip);

        $this->assertEquals($exported, $written, "same rows per table (written parents-first, so in another order)");
        $this->assertSame($before, $context->runFor($a, fn () => LookupCategory::query()->orderBy('id')->get(['id', 'code'])->toArray()));

        // The next new row does not collide with a copied id.
        $next = $context->runFor($a, fn () => LookupCategory::query()->create(['code' => 'a-3', 'name' => 'after']));
        $this->assertGreaterThan(max(array_column($before, 'id')), $next->id);
    }

    public function test_tables_are_copied_parents_first(): void
    {
        $order = app(SchoolImporter::class)->parentsFirst(DB::connection(), SchoolTables::all(DB::connection()));

        $this->assertLessThan(array_search('lookup_values', $order, true), array_search('lookup_categories', $order, true));
        $this->assertLessThan(array_search('lookup_value_translations', $order, true), array_search('lookup_values', $order, true));
    }

    public function test_only_allowed_moves_start(): void
    {
        Queue::fake();
        $this->actingAsStaff($this->platformStaff());
        $shared = $this->school('share-a');
        $dedicated = $this->school('own', 'dedicated');

        $this->postJson("/api/v1/platform/schools/{$dedicated->id}/moves", ['placement' => 'shared'])->assertUnprocessable(); // never into shared
        $this->postJson("/api/v1/platform/schools/{$dedicated->id}/moves", ['placement' => 'dedicated'])->assertUnprocessable(); // already there

        $this->postJson("/api/v1/platform/schools/{$shared->id}/moves", ['placement' => 'dedicated'])->assertCreated()
            ->assertJsonPath('data.from.mode', 'shared')
            ->assertJsonPath('data.to.mode', 'dedicated')
            ->assertJsonPath('data.to.database', 'tn_share-a');
        Queue::assertPushed(MoveSchool::class);

        // One move at a time.
        $this->postJson("/api/v1/platform/schools/{$shared->id}/moves", ['placement' => 'dedicated'])->assertUnprocessable();
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'school.move_started', 'tenant_id' => $shared->id]);
    }

    public function test_a_separate_target_password_is_stored_encrypted_and_never_returned(): void
    {
        Queue::fake();
        $this->actingAsStaff($this->platformStaff());
        $shared = $this->school('share-a');

        $response = $this->postJson("/api/v1/platform/schools/{$shared->id}/moves", ['placement' => 'separate', 'server' => [
            'host' => 'db.far.example', 'port' => 5432, 'database' => 'school_far', 'username' => 'school_far', 'password' => 'top-s3cret', 'sslmode' => 'require',
        ]])->assertCreated();

        $this->assertStringNotContainsString('top-s3cret', $response->getContent());
        $this->assertStringNotContainsString('top-s3cret', (string) DB::table('bo_school_moves')->value('to_placement'));
        $this->assertSame('top-s3cret', SchoolMove::query()->sole()->to_placement['password']);
    }

    public function test_moving_needs_the_placement_permission(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.school.read', 'platform.school.write']));
        $shared = $this->school('share-a');

        $this->getJson("/api/v1/platform/schools/{$shared->id}/moves")->assertOk();
        $this->postJson("/api/v1/platform/schools/{$shared->id}/moves", ['placement' => 'dedicated'])->assertNotFound();
    }

    public function test_an_old_copy_is_removed_only_after_a_finished_move(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $shared = $this->school('share-a');
        $move = SchoolMove::query()->create([
            'tenant_id' => $shared->id, 'from_mode' => 'shared', 'to_mode' => 'dedicated',
            'from_placement' => ['mode' => 'shared', 'database' => 'school_share'], 'to_placement' => ['mode' => 'dedicated', 'database' => 'tn_share-a'],
            'status' => SchoolMove::FAILED,
        ]);

        $this->postJson("/api/v1/platform/school-moves/{$move->id}/remove-old-copy")->assertUnprocessable();
    }
}
