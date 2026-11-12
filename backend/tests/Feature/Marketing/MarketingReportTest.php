<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Models\Inquiry;
use App\Models\MarketingCampaign;
use App\Models\Payment;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Marketing > Report and the dashboard card: leads, enrolled, conversion, cost and fees, by source and campaign. */
class MarketingReportTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function inquiry(array $attributes): Inquiry
    {
        return Inquiry::query()->create(['inquiry_date' => '2026-11-05', 'name' => 'Lead', 'phone' => '012', 'source' => 'facebook', ...$attributes]);
    }

    private function pay(Student $student, float $amount, string $date, string $currency = 'USD'): void
    {
        Payment::factory()->create(['student_id' => $student->id, 'amount' => $amount, 'currency' => $currency, 'payment_date' => $date]);
    }

    public function test_the_report_counts_by_source_and_campaign_with_cost_and_fees(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CAMPAIGNS_VIEW]);
        $ads = MarketingCampaign::factory()->create(['name' => 'Facebook ads', 'spent' => 100, 'currency' => 'USD']);
        $sok = Student::factory()->create();
        $chan = Student::factory()->create();

        $this->inquiry(['marketing_campaign_id' => $ads->id, 'status' => 'enrolled', 'student_id' => $sok->id]);
        $this->inquiry(['marketing_campaign_id' => $ads->id]);
        $this->inquiry(['marketing_campaign_id' => $ads->id, 'status' => 'not_interested']);
        $this->inquiry(['marketing_campaign_id' => $ads->id, 'source' => 'telegram']);
        $this->inquiry(['source' => 'walk_in', 'status' => 'enrolled', 'student_id' => $chan->id]);
        $this->inquiry(['inquiry_date' => '2026-10-20']); // outside the period

        $this->pay($sok, 80, '2026-11-06');
        $this->pay($sok, 30, '2026-10-01'); // before they asked: not counted
        $this->pay($chan, 50, '2026-11-10');

        $data = $this->getJson('/api/v1/marketing-report?from=2026-11-01&to=2026-11-30')->assertOk()->json('data');

        $this->assertSame(5, $data['totals']['leads']);
        $this->assertSame(2, $data['totals']['enrolled']);
        $this->assertSame(40.0, (float) $data['totals']['conversion']);
        $this->assertSame([['currency' => 'USD', 'amount' => 130]], array_map(fn ($r) => ['currency' => $r['currency'], 'amount' => (int) $r['amount']], $data['totals']['fees']));

        $sources = collect($data['by_source'])->keyBy('source');
        $this->assertSame(3, $sources['facebook']['leads']);
        $this->assertSame(1, $sources['telegram']['leads']);
        $this->assertSame(1, $sources['walk_in']['enrolled']);

        $first = $data['by_campaign'][0];
        $this->assertSame($ads->id, $first['id']);
        $this->assertSame(4, $first['leads']);
        $this->assertSame(1, $first['enrolled']);
        $this->assertSame(1, $first['not_interested']);
        $this->assertSame(25.0, (float) $first['cost_per_lead']);
        $this->assertSame(100.0, (float) $first['cost_per_student']);
        $this->assertSame(80.0, (float) $first['fees'][0]['amount']);

        $none = end($data['by_campaign']);
        $this->assertNull($none['id']);
        $this->assertSame(1, $none['leads']);
    }

    public function test_the_dashboard_card_summary_needs_the_card_or_campaigns(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(12, 0));
        $this->actingAsAdminWithPermissions([Permissions::DASHBOARD_CARDS_MARKETING]);
        $student = Student::factory()->create();
        $this->inquiry(['inquiry_date' => '2026-11-03']);
        $this->inquiry(['inquiry_date' => '2026-11-04', 'source' => 'telegram', 'status' => 'enrolled', 'student_id' => $student->id]);
        $this->inquiry(['inquiry_date' => '2026-11-05']);

        $this->getJson('/api/v1/marketing-report/summary')->assertOk()
            ->assertJsonPath('data.leads', 3)
            ->assertJsonPath('data.enrolled', 1)
            ->assertJsonPath('data.top_source', 'facebook');
        $this->getJson('/api/v1/marketing-report')->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::INQUIRIES_VIEW]);
        $this->getJson('/api/v1/marketing-report/summary')->assertForbidden();
    }
}
