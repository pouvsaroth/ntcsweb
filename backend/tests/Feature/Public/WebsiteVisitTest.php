<?php

declare(strict_types=1);

namespace Tests\Feature\Public;

use App\Models\Tenant;
use App\Models\WebsiteVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The public footer's small "visitors" line — see WebsiteVisitStats on the
 * backend. Fully public: no permission or admin session involved anywhere
 * here, only `tenant.required` on the route group.
 */
class WebsiteVisitTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_recording_a_visit_creates_todays_row(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        $response = $this->withHeader('X-Tenant', $tenant->slug)->postJson('/api/v1/public/visits');

        $response->assertOk();
        $response->assertJsonPath('data.today', 1);
        $this->assertSame(1, WebsiteVisit::query()->where('visit_date', Carbon::today()->toDateString())->value('visits'));
    }

    public function test_recording_a_visit_twice_the_same_day_increments_the_same_row(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        $this->withHeader('X-Tenant', $tenant->slug)->postJson('/api/v1/public/visits')->assertOk();
        $response = $this->withHeader('X-Tenant', $tenant->slug)->postJson('/api/v1/public/visits');

        $response->assertOk();
        $response->assertJsonPath('data.today', 2);
        $this->assertSame(1, WebsiteVisit::query()->count());
    }

    public function test_stats_are_summed_across_the_right_date_ranges(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17')); // mid-month, mid-year, a Wednesday

        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        WebsiteVisit::factory()->create(['visit_date' => '2026-06-17', 'visits' => 5]); // today
        WebsiteVisit::factory()->create(['visit_date' => '2026-06-16', 'visits' => 3]); // yesterday, also this week/month/year
        WebsiteVisit::factory()->create(['visit_date' => '2026-06-01', 'visits' => 2]); // this month/year, not this week (week starts Monday 06-15)
        WebsiteVisit::factory()->create(['visit_date' => '2026-01-01', 'visits' => 4]); // this year only
        WebsiteVisit::factory()->create(['visit_date' => '2025-12-31', 'visits' => 9]); // last year — excluded from everything

        $response = $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/visits');

        $response->assertOk();
        $response->assertJsonPath('data.today', 5);
        $response->assertJsonPath('data.yesterday', 3);
        $response->assertJsonPath('data.weekly', 8);
        $response->assertJsonPath('data.monthly', 10);
        $response->assertJsonPath('data.yearly', 14);
    }

    public function test_the_stats_endpoint_never_increments_anything(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/visits')->assertOk();

        $this->assertSame(0, WebsiteVisit::query()->count());
    }
}
