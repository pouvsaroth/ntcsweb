<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Models\MarketingBroadcast;
use App\Models\MarketingCampaign;
use App\Models\NotificationGroup;
use App\Models\Tenant;
use App\Platform\Models\SupportSettings;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Marketing > Broadcast: a promotion sent to the school's linked Telegram groups, with each group's result kept. */
class MarketingBroadcastTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::BROADCASTS_VIEW, Permissions::BROADCASTS_CREATE, Permissions::BROADCASTS_DELETE];

    private function group(string $name, ?string $chat, ?int $tenantId = null): NotificationGroup
    {
        return NotificationGroup::query()->create([
            'tenant_id' => $tenantId ?? $this->tenant->id,
            'name' => $name,
            'telegram_chat_id' => $chat,
            'telegram_title' => $chat ? $name.' (Telegram)' : null,
            'linked_at' => $chat ? now() : null,
        ]);
    }

    private function botOn(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        SupportSettings::current()->update(['telegram_bot_token' => '123:test']);
    }

    public function test_a_broadcast_goes_to_the_picked_groups_and_keeps_each_result(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $this->botOn();
        $parents = $this->group('Parents', '-100111');
        $promo = $this->group('Promo', '-100222');
        $this->group('Not linked', null);
        $campaign = MarketingCampaign::factory()->create();

        Http::fake(fn ($request) => str_contains((string) $request->body(), '-100222')
            ? Http::response(['ok' => false, 'description' => 'Forbidden: bot was kicked'], 403)
            : Http::response(['ok' => true, 'result' => []]));

        $groups = $this->getJson('/api/v1/marketing-broadcasts/groups')->assertOk()->json('data');
        $this->assertTrue($groups['bot_ready']);
        $this->assertSame(['Parents', 'Promo'], array_column($groups['groups'], 'name'));

        $sent = $this->postJson('/api/v1/marketing-broadcasts', [
            'title' => 'New term', 'message' => 'Enrol now and get 10% off!', 'marketing_campaign_id' => $campaign->id,
            'group_ids' => [$parents->id, $promo->id],
        ])->assertCreated()->json('data');

        $this->assertSame('partial', $sent['status']);
        $this->assertTrue($sent['results'][0]['ok']);
        $this->assertFalse($sent['results'][1]['ok']);
        $this->assertSame($campaign->id, $sent['campaign']['id']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['text'] === 'Enrol now and get 10% off!');

        $this->getJson('/api/v1/marketing-broadcasts')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/marketing-broadcasts/{$sent['id']}")->assertNoContent();
        $this->assertSame(0, MarketingBroadcast::query()->count());
    }

    public function test_only_this_schools_linked_groups_can_be_picked(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $this->botOn();
        Http::fake();
        $unlinked = $this->group('Not linked', null);
        $other = $this->group('Other school', '-100999', Tenant::factory()->create()->id);

        foreach ([$unlinked->id, $other->id] as $id) {
            $this->postJson('/api/v1/marketing-broadcasts', ['title' => 'X', 'message' => 'Y', 'group_ids' => [$id]])
                ->assertUnprocessable()->assertJsonValidationErrors('group_ids');
        }
        Http::assertNothingSent();
    }

    public function test_it_needs_the_permissions(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BROADCASTS_VIEW]);
        Http::fake();
        $group = $this->group('Parents', '-100111');

        $this->getJson('/api/v1/marketing-broadcasts')->assertOk();
        $this->postJson('/api/v1/marketing-broadcasts', ['title' => 'X', 'message' => 'Y', 'group_ids' => [$group->id]])->assertForbidden();
        Http::assertNothingSent();
    }
}
