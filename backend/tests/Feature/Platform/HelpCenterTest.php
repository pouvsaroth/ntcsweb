<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\Models\HelpArticle;
use App\Platform\Models\HelpFaq;
use App\Platform\Models\Plan;
use App\Platform\Models\SchoolSubscription;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/** Stage 13a (docs/backoffice/stage-13-help-support.md): help articles, FAQ, contacts. */
class HelpCenterTest extends TestCase
{
    use HasAcademicAdmin, HasPlatformStaff, RefreshDatabase;

    private const BO = '/api/v1/platform/support/help';

    public function test_back_office_writes_articles_faq_and_contacts_and_readers_cannot(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.support.read', 'platform.support.help']));

        $article = $this->postJson(self::BO.'/articles', [
            'module' => 'hrm', 'title_en' => 'Add a staff member', 'title_km' => 'បន្ថែមបុគ្គលិក',
            'body_en' => "Go to HRM → Staff.\nPress Add.", 'status' => 'published',
        ])->assertCreated()->assertJsonPath('data.module', 'hrm')->json('data');
        $this->postJson(self::BO.'/articles', ['title_en' => 'No body', 'status' => 'draft'])->assertUnprocessable()->assertJsonValidationErrors('body_en');
        $this->postJson(self::BO.'/articles', ['title_en' => 'X', 'body_en' => 'Y', 'module' => 'nope', 'status' => 'draft'])->assertUnprocessable()->assertJsonValidationErrors('module');
        $this->putJson(self::BO."/articles/{$article['id']}", [...$article, 'status' => 'draft'])->assertOk()->assertJsonPath('data.status', 'draft');

        $this->postJson(self::BO.'/faqs', ['question_km' => 'តើខ្ញុំប្តូរពាក្យសម្ងាត់ដោយរបៀបណា?', 'answer_km' => 'ចុចលើរូបរបស់អ្នក។', 'status' => 'published'])->assertCreated();
        $this->putJson(self::BO.'/contact', ['help_phone' => '012 345 678', 'help_telegram' => '@newtechkh', 'help_email' => 'support@newtechkh.com', 'help_hours_en' => 'Mon–Sat 8:00–17:00'])
            ->assertOk()->assertJsonPath('data.telegram', '@newtechkh');
        $this->putJson(self::BO.'/contact', ['help_email' => 'not-an-email'])->assertUnprocessable();

        $this->getJson(self::BO.'/modules')->assertOk()->assertJsonFragment(['code' => 'hrm']);
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'support.help_article_created']);

        // Read-only support staff see the lists but cannot write.
        $this->actingAsStaff($this->platformStaff(['platform.support.read']));
        $this->getJson(self::BO.'/articles')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::BO.'/faqs')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson(self::BO.'/faqs', ['question_en' => 'Q', 'answer_en' => 'A', 'status' => 'draft'])->assertNotFound(); // the back office hides what staff may not do
        $this->deleteJson(self::BO."/articles/{$article['id']}")->assertNotFound();
    }

    public function test_a_school_sees_published_help_for_its_plan_with_the_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::HELP_SUPPORT_VIEW]);
        $plan = Plan::query()->create([
            'code' => 'p-help', 'name' => 'Academics only', 'price' => 10, 'currency' => 'USD',
            'period_months' => 1, 'modules' => ['academics'], 'status' => Plan::STATUS_ACTIVE,
        ]);
        SchoolSubscription::query()->create(['tenant_id' => $this->tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'start_on' => now()->subMonth()->toDateString(), 'end_on' => now()->addMonth()->toDateString()]);

        $general = HelpArticle::query()->create(['title_en' => 'Getting started', 'body_en' => str_repeat('Welcome. ', 60), 'status' => 'published', 'sort_order' => 2]);
        $academics = HelpArticle::query()->create(['module' => 'academics', 'title_en' => 'Add a class', 'body_en' => 'Academic → Classes', 'status' => 'published', 'sort_order' => 1]);
        $hrm = HelpArticle::query()->create(['module' => 'hrm', 'title_en' => 'Payroll', 'body_en' => 'Not on this plan', 'status' => 'published']);
        HelpArticle::query()->create(['title_en' => 'Draft', 'body_en' => 'Hidden', 'status' => 'draft']);
        HelpFaq::query()->create(['question_en' => 'Reset password?', 'answer_en' => 'Ask your admin.', 'status' => 'published']);
        HelpFaq::query()->create(['module' => 'hrm', 'question_en' => 'Payslips?', 'answer_en' => 'HRM only', 'status' => 'published']);

        $data = $this->getJson('/api/v1/help')->assertOk()->json('data');
        $this->assertSame([$academics->id, $general->id], array_column($data['articles'], 'id'));
        $this->assertStringEndsWith('…', $data['articles'][1]['summary_en']);
        $this->assertArrayNotHasKey('body_en', $data['articles'][0]);
        $this->assertSame(['Reset password?'], array_column($data['faqs'], 'question_en'));
        $this->assertSame(['academics'], array_column($data['modules'], 'code'));
        $this->assertArrayHasKey('phone', $data['contact']);

        $this->getJson("/api/v1/help/articles/{$academics->id}")->assertOk()->assertJsonPath('data.body_en', 'Academic → Classes');
        $this->getJson("/api/v1/help/articles/{$hrm->id}")->assertNotFound();

        // Without the permission: no Help & Support.
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/v1/help')->assertForbidden();
    }
}
