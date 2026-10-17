<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Platform\Models\SchoolPlacement;
use App\Platform\Services\SchoolDatabase;
use App\Platform\Services\SeparateServerProbe;
use App\Support\Tenancy\SchoolDatabaseConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 4b (docs/backoffice/stage-4-database-placement.md): where a new
 * school's data lives — shared (default), dedicated or a separate server.
 */
class PlatformPlacementTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private array $probeResult = ['ok' => true, 'message' => 'Connected as school_far (PostgreSQL 18.4).', 'superuser' => false, 'can_create' => true];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SchoolDatabase::class, new FakeSchoolDatabase);
        $test = $this;
        $this->app->instance(SeparateServerProbe::class, new class($test) extends SeparateServerProbe
        {
            public function __construct(private PlatformPlacementTest $test) {}

            public function probe(array $server): array
            {
                return $this->test->probeAnswer($server);
            }
        });
        Notification::fake();
    }

    public function probeAnswer(array $server): array
    {
        return $server['password'] === 'right' ? $this->probeResult : ['ok' => false, 'message' => 'password authentication failed for user "school_far"'];
    }

    private function create(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/platform/schools', [
            'name' => 'Angkor', 'slug' => 'angkor', 'owner_name' => 'Dara', 'owner_email' => 'dara@angkor.test',
        ] + $extra);
    }

    private function server(string $password = 'right'): array
    {
        return ['host' => 'db.far.example', 'port' => 5432, 'database' => 'school_far', 'username' => 'school_far', 'password' => $password, 'sslmode' => 'require'];
    }

    public function test_a_new_school_is_shared_unless_staff_choose_otherwise(): void
    {
        $this->actingAsStaff($this->platformStaff());

        $this->create()->assertCreated()
            ->assertJsonPath('data.placement.mode', 'shared')
            ->assertJsonPath('data.database', config('tenancy.database.shared_database'));
    }

    public function test_a_dedicated_school_gets_its_own_database(): void
    {
        $this->actingAsStaff($this->platformStaff());

        $this->create(['placement' => 'dedicated'])->assertCreated()
            ->assertJsonPath('data.placement.mode', 'dedicated')
            ->assertJsonPath('data.database', config('tenancy.database.name_prefix').'angkor');
    }

    public function test_a_separate_server_must_answer_first_and_its_password_never_comes_back(): void
    {
        $this->actingAsStaff($this->platformStaff());

        $this->create(['placement' => 'separate', 'server' => $this->server('wrong')])
            ->assertUnprocessable()
            ->assertJsonPath('errors.server.0', 'password authentication failed for user "school_far"');
        $this->assertSame(0, Tenant::query()->where('slug', 'angkor')->count(), 'nothing created for a server that does not answer');

        $response = $this->create(['placement' => 'separate', 'server' => $this->server()])->assertCreated()
            ->assertJsonPath('data.placement.mode', 'separate')
            ->assertJsonPath('data.placement.host', 'db.far.example')
            ->assertJsonPath('data.placement.username', 'school_far');
        $this->assertStringNotContainsString('right', $response->getContent());

        $stored = DB::table('bo_school_placements')->where('tenant_id', $response->json('data.id'))->value('password');
        $this->assertNotSame('right', $stored, 'stored encrypted');
        $this->assertSame('right', SchoolPlacement::query()->find($response->json('data.id'))->password);
    }

    public function test_test_connection_reports_and_saves_nothing(): void
    {
        $this->actingAsStaff($this->platformStaff());

        $this->postJson('/api/v1/platform/schools/test-connection', $this->server())->assertOk()->assertJsonPath('data.ok', true);
        $this->postJson('/api/v1/platform/schools/test-connection', $this->server('wrong'))->assertOk()->assertJsonPath('data.ok', false);
        $this->assertSame(0, SchoolPlacement::query()->count());

        $this->actingAsStaff($this->platformStaff(['platform.school.read']));
        $this->postJson('/api/v1/platform/schools/test-connection', $this->server())->assertNotFound();
    }

    public function test_each_schools_connection_follows_its_placement(): void
    {
        $shared = Tenant::factory()->create(['slug' => 'in-shared']);
        $dedicated = Tenant::factory()->create(['slug' => 'own-db']);
        $far = Tenant::factory()->create(['slug' => 'far-away']);
        $legacy = Tenant::factory()->create(['slug' => 'before-placements']);

        SchoolPlacement::query()->create(['tenant_id' => $shared->id, 'mode' => 'shared', 'database' => 'school_share']);
        SchoolPlacement::query()->create(['tenant_id' => $dedicated->id, 'mode' => 'dedicated', 'database' => 'tn_own-db']);
        SchoolPlacement::query()->create(['tenant_id' => $far->id, 'mode' => 'separate', 'database' => 'school_far', 'host' => 'db.far.example', 'port' => 6543, 'username' => 'school_far', 'password' => 's3cret', 'sslmode' => 'require']);

        $this->assertInstanceOf(SchoolDatabaseConfig::class, $shared->database());
        $this->assertSame('school_share', $shared->database()->connection()['database']);
        $this->assertSame('tn_own-db', $dedicated->database()->connection()['database']);
        $this->assertSame(config('tenancy.database.name_prefix').'before-placements', $legacy->database()->getName(), 'no row = dedicated, as before');

        $c = $far->database()->connection();
        $this->assertSame(['school_far', 'db.far.example', 6543, 'school_far', 's3cret', 'require', ''], [$c['database'], $c['host'], $c['port'], $c['username'], $c['password'], $c['sslmode'], $c['app_role']]);
        $this->assertArrayNotHasKey('app_role', $shared->database()->connection(), 'main-server schools use the configured app role');
    }
}
