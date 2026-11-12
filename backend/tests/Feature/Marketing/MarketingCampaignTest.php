<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Models\Inquiry;
use App\Models\LookupCategory;
use App\Models\LookupValue;
use App\Models\MarketingCampaign;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Marketing > Campaigns: channel, dates, budget and real cost; leads and enrolled from linked inquiries. */
class MarketingCampaignTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [
        Permissions::CAMPAIGNS_VIEW, Permissions::CAMPAIGNS_CREATE, Permissions::CAMPAIGNS_UPDATE, Permissions::CAMPAIGNS_DELETE,
        Permissions::INQUIRIES_VIEW, Permissions::INQUIRIES_CREATE, Permissions::INQUIRIES_UPDATE,
    ];

    /** Signs in with these permissions, then adds the channels (Base Data is per school). */
    private function as(array $permissions): void
    {
        $this->actingAsAdminWithPermissions($permissions);
        $category = LookupCategory::query()->create(['code' => 'MARKETING_CHANNEL', 'name' => 'Marketing Channel']);
        foreach (['FACEBOOK', 'FLYER'] as $i => $code) {
            LookupValue::query()->create(['lookup_category_id' => $category->id, 'code' => $code, 'sort_order' => $i]);
        }
    }

    private function create(array $overrides = []): array
    {
        return $this->postJson('/api/v1/marketing-campaigns', [
            'name' => 'New term Facebook ads',
            'channel' => 'FACEBOOK',
            'start_on' => '2026-11-01',
            'end_on' => '2026-11-30',
            'currency' => 'USD',
            'budget' => 300,
            'spent' => 120,
            'status' => 'active',
            ...$overrides,
        ])->assertCreated()->json('data');
    }

    public function test_a_campaign_is_added_with_a_code_and_listed(): void
    {
        $this->as(self::ALL);
        $created = $this->create();
        $this->create(['name' => 'Market flyers', 'channel' => 'FLYER', 'status' => 'planned']);

        $this->assertSame(sprintf('C%05d', $created['id']), $created['code']);
        $this->assertSame(300.0, (float) $created['budget']);
        $this->getJson('/api/v1/marketing-campaigns')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/marketing-campaigns?search=flyer')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/marketing-campaigns?filter[channel]=FACEBOOK')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_channel_must_come_from_base_data_and_the_end_cannot_be_before_the_start(): void
    {
        $this->as(self::ALL);
        $this->postJson('/api/v1/marketing-campaigns', ['name' => 'X', 'channel' => 'NOPE', 'start_on' => '2026-11-01', 'currency' => 'USD'])
            ->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->postJson('/api/v1/marketing-campaigns', ['name' => 'X', 'channel' => 'FLYER', 'start_on' => '2026-11-10', 'end_on' => '2026-11-01', 'currency' => 'USD'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_on');

        $created = $this->create();
        $this->putJson("/api/v1/marketing-campaigns/{$created['id']}", ['end_on' => '2026-10-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_on');
        $this->putJson("/api/v1/marketing-campaigns/{$created['id']}", ['spent' => 250, 'status' => 'completed'])
            ->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_inquiries_pick_a_campaign_and_it_counts_leads_enrolled_and_cost(): void
    {
        $this->as(self::ALL);
        $campaign = $this->create(['spent' => 120]);

        foreach (['Sok', 'Chan', 'Dara'] as $name) {
            $this->postJson('/api/v1/inquiries', [
                'inquiry_date' => '2026-11-05', 'name' => $name, 'phone' => '012000111', 'source' => 'facebook',
                'marketing_campaign_id' => $campaign['id'],
            ])->assertCreated()->assertJsonPath('data.campaign.id', $campaign['id']);
        }
        $student = Student::factory()->create();
        Inquiry::query()->where('name', 'Sok')->update(['status' => 'enrolled', 'student_id' => $student->id]);

        $row = $this->getJson("/api/v1/marketing-campaigns/{$campaign['id']}")->assertOk()->json('data');
        $this->assertSame(3, $row['leads_count']);
        $this->assertSame(1, $row['enrolled_count']);
        $this->assertSame(40.0, (float) $row['cost_per_lead']);
        $this->assertSame(120.0, (float) $row['cost_per_student']);

        $this->getJson('/api/v1/inquiries?filter[marketing_campaign_id]='.$campaign['id'])->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_the_inquiry_picker_lists_open_campaigns_to_front_desk_users(): void
    {
        $this->as([Permissions::INQUIRIES_VIEW]);
        $open = MarketingCampaign::factory()->create(['status' => 'active']);
        $done = MarketingCampaign::factory()->create(['status' => 'completed', 'name' => 'Old flyers']);

        $ids = collect($this->getJson('/api/v1/inquiries/campaign-options')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$open->id], $ids);
        // The one already picked still shows when editing.
        $ids = collect($this->getJson('/api/v1/inquiries/campaign-options?include='.$done->id)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$open->id, $done->id], $ids);

        $this->getJson('/api/v1/marketing-campaigns')->assertForbidden();
    }

    public function test_deleting_a_campaign_keeps_its_inquiries(): void
    {
        $this->as(self::ALL);
        $campaign = MarketingCampaign::factory()->create();
        $inquiry = Inquiry::query()->create(['inquiry_date' => '2026-11-05', 'name' => 'Sok', 'phone' => '012', 'source' => 'facebook', 'marketing_campaign_id' => $campaign->id]);

        $this->deleteJson("/api/v1/marketing-campaigns/{$campaign->id}")->assertNoContent();
        $this->assertNull($inquiry->fresh()->marketing_campaign_id);
    }

    public function test_without_permission_campaigns_cannot_be_changed(): void
    {
        $this->as([Permissions::CAMPAIGNS_VIEW]);
        $campaign = MarketingCampaign::factory()->create();

        $this->getJson('/api/v1/marketing-campaigns')->assertOk();
        $this->postJson('/api/v1/marketing-campaigns', ['name' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/marketing-campaigns/{$campaign->id}", ['name' => 'Y'])->assertForbidden();
        $this->deleteJson("/api/v1/marketing-campaigns/{$campaign->id}")->assertForbidden();
    }
}
