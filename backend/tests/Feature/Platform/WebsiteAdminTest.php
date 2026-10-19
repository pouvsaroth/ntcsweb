<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\DownloadFolder;
use App\Models\GalleryImage;
use App\Models\HomeSlide;
use App\Models\Promotion;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 7a (docs/backoffice/stage-7-website.md): the back office edits
 * NewTech's public website — and only NewTech's.
 */
class WebsiteAdminTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private Tenant $newtech;

    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['platform.website_school' => 'newtech']);
        $this->newtech = Tenant::factory()->create(['slug' => 'newtech']);
        $this->other = Tenant::factory()->create(['slug' => 'other']);
    }

    private function manager(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.website.read', 'platform.website.manage']));
    }

    /** Live (not removed) rows of a website model in one school, ignoring the current tenant scope. */
    private function countIn(string $model, Tenant $school): int
    {
        $query = $model::query()->withoutGlobalScopes()->where('tenant_id', $school->id);
        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->whereNull('deleted_at');
        }

        return $query->count();
    }

    public function test_it_says_which_school_it_edits(): void
    {
        $this->manager();

        $this->getJson('/api/v1/platform/website')->assertOk()
            ->assertJsonPath('data.slug', 'newtech')
            ->assertJsonPath('data.id', $this->newtech->id);
    }

    public function test_read_alone_sees_but_cannot_change_and_nobody_else_sees(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.school.read']));
        $this->getJson('/api/v1/platform/website/slides')->assertNotFound();

        $this->actingAsStaff($this->platformStaff(['platform.website.read']));
        $this->getJson('/api/v1/platform/website/slides')->assertOk();
        $this->postJson('/api/v1/platform/website/slides', ['image' => UploadedFile::fake()->image('a.jpg')])->assertNotFound();
        $this->assertSame(0, $this->countIn(HomeSlide::class, $this->newtech));
    }

    public function test_slides_gallery_and_promotions_are_added_changed_and_removed_in_newtech_only(): void
    {
        $this->manager();

        foreach (['slides' => [HomeSlide::class, ['title' => 'Welcome']], 'gallery' => [GalleryImage::class, ['caption' => 'Lab']], 'promotions' => [Promotion::class, ['title' => '20% off']]] as $kind => [$model, $fields]) {
            $id = $this->post("/api/v1/platform/website/{$kind}", [...$fields, 'image' => UploadedFile::fake()->image('a.jpg'), 'sort_order' => 1, 'status' => 'active'])
                ->assertCreated()->json('data.id');

            $this->assertSame(1, $this->countIn($model, $this->newtech), $kind);
            $this->assertSame(0, $this->countIn($model, $this->other), $kind);
            $row = $model::query()->withoutGlobalScopes()->findOrFail($id);
            $this->assertStringStartsWith("tenants/{$this->newtech->id}/", $row->image_path);
            Storage::disk('public')->assertExists($row->image_path);

            $this->getJson("/api/v1/platform/website/{$kind}")->assertOk()->assertJsonPath('data.0.id', $id);

            $old = $row->image_path;
            $this->post("/api/v1/platform/website/{$kind}/{$id}", ['_method' => 'PUT', 'status' => 'inactive', 'image' => UploadedFile::fake()->image('b.jpg')])
                ->assertOk()->assertJsonPath('data.status', 'inactive');
            Storage::disk('public')->assertMissing($old);

            $this->deleteJson("/api/v1/platform/website/{$kind}/{$id}")->assertNoContent();
            $this->assertSame(0, $this->countIn($model, $this->newtech), $kind);

            foreach (['created', 'updated', 'deleted'] as $what) {
                $this->assertDatabaseHas('bo_audit_logs', ['action' => "website.{$kind}.{$what}", 'tenant_id' => $this->newtech->id]);
            }
        }
    }

    public function test_another_schools_item_cannot_be_reached(): void
    {
        $this->manager();
        app(TenantContext::class)->set($this->other);
        $theirs = new HomeSlide(['title' => 'Theirs', 'status' => 'active', 'sort_order' => 0]);
        $theirs->forceFill(['tenant_id' => $this->other->id, 'image_path' => 'x.jpg'])->save();
        $this->assertSame(1, $this->countIn(HomeSlide::class, $this->other));

        $this->deleteJson("/api/v1/platform/website/slides/{$theirs->id}")->assertNotFound();
        // Row-level security follows the school in context; look as that school again.
        app(TenantContext::class)->set($this->other);
        $this->assertSame(1, $this->countIn(HomeSlide::class, $this->other));
    }

    public function test_download_folders_and_files(): void
    {
        $this->manager();

        $folder = $this->postJson('/api/v1/platform/website/download-folders', ['name' => 'Forms'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/platform/website/download-folders/{$folder}", ['name' => 'School forms'])->assertOk()->assertJsonPath('data.name', 'School forms');

        $file = $this->post("/api/v1/platform/website/download-folders/{$folder}/files", ['file' => UploadedFile::fake()->create('rules.pdf', 20, 'application/pdf')])
            ->assertCreated()->assertJsonPath('data.name', 'rules')->json('data.id');
        $this->getJson("/api/v1/platform/website/download-folders/{$folder}/files")->assertOk()->assertJsonPath('data.0.id', $file);
        $this->patchJson("/api/v1/platform/website/download-files/{$file}", ['name' => 'School rules'])->assertOk();
        $this->deleteJson("/api/v1/platform/website/download-files/{$file}")->assertNoContent();

        $this->getJson('/api/v1/platform/website/download-folders')->assertOk()->assertJsonPath('data.0.name', 'School forms');
        $this->deleteJson("/api/v1/platform/website/download-folders/{$folder}")->assertNoContent();
        $this->assertSame(0, $this->countIn(DownloadFolder::class, $this->newtech));
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'website.download_file.created', 'tenant_id' => $this->newtech->id]);
    }

    public function test_the_about_page(): void
    {
        $this->manager();

        $this->post('/api/v1/platform/website/about', [
            'history_title' => 'Our story',
            'history_paragraph_1' => 'Since 2010.',
            'history_image' => UploadedFile::fake()->image('h.jpg'),
        ])->assertOk()->assertJsonPath('data.history_title', 'Our story');

        $this->assertSame('Our story', $this->newtech->fresh()->setting('about.history_title'));
        $this->assertNull($this->other->fresh()->setting('about'));
        $this->getJson('/api/v1/platform/website/about')->assertOk()->assertJsonPath('data.history_paragraph_1', 'Since 2010.');
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'website.about.updated', 'tenant_id' => $this->newtech->id]);
    }

    // ── The services front page: a list of cards ──

    private function addCard(array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/v1/platform/website/services/cards', ['kind' => 'page', 'status' => 'active', ...$fields], ['Accept' => 'application/json']);
    }

    public function test_the_services_page_is_off_and_empty_by_default(): void
    {
        $this->manager();

        $this->getJson('/api/v1/platform/website/services')->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.cards', []);
        $this->withHeader('X-Tenant', 'newtech')->getJson('/api/v1/public/settings')->assertOk()->assertJsonPath('data.services', null);
    }

    public function test_cards_are_added_changed_ordered_hidden_and_removed(): void
    {
        $this->manager();

        $school = $this->addCard(['kind' => 'school', 'title_en' => 'Computer School', 'title_km' => 'សាលាកុំព្យូទ័រ', 'sort_order' => 0, 'image' => UploadedFile::fake()->image('cs.jpg')])
            ->assertCreated()->assertJsonPath('data.kind', 'school')->assertJsonPath('data.slug', '')->json('data.id');
        $sms = $this->addCard(['title_en' => 'School Management System', 'heading_en' => 'Run your school', 'features_en' => "Students\nInvoices",
            'button_label_en' => 'Sign in', 'button_url' => 'https://erp.newtechkh.com', 'sort_order' => 1])
            ->assertCreated()->assertJsonPath('data.slug', 'school-management-system')->json('data.id');
        $this->addCard(['title_en' => 'Mobile App', 'slug' => 'mobile', 'sort_order' => 2, 'status' => 'inactive'])->assertCreated()->assertJsonPath('data.slug', 'mobile');

        // A second card cannot open the Computer School, nor take a page address in use.
        $this->addCard(['kind' => 'school', 'title_en' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->addCard(['title_en' => 'Copy', 'slug' => 'mobile'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        // A same-titled page gets its own address.
        $this->addCard(['title_en' => 'School Management System', 'sort_order' => 3])->assertCreated()->assertJsonPath('data.slug', 'school-management-system-2');

        $cards = $this->getJson('/api/v1/platform/website/services')->assertOk()->json('data.cards');
        $this->assertSame(['Computer School', 'School Management System', 'Mobile App', 'School Management System'], array_column($cards, 'title_en'));

        // Changing a card keeps its picture unless a new one comes; a new one replaces the file.
        $oldPath = $this->newtech->fresh()->setting('services.cards')[0]['image_path'];
        $this->post("/api/v1/platform/website/services/cards/{$school}", ['_method' => 'PUT', 'kind' => 'school', 'status' => 'active', 'title_en' => 'Computer School', 'text_en' => 'Courses'])
            ->assertOk()->assertJsonPath('data.text_en', 'Courses');
        Storage::disk('public')->assertExists($oldPath);
        $this->post("/api/v1/platform/website/services/cards/{$school}", ['_method' => 'PUT', 'kind' => 'school', 'status' => 'active', 'image' => UploadedFile::fake()->image('new.jpg')])->assertOk();
        Storage::disk('public')->assertMissing($oldPath);

        // On: the public site gets the shown cards only, in order.
        $this->putJson('/api/v1/platform/website/services/settings', ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
        $public = $this->withHeader('X-Tenant', 'newtech')->getJson('/api/v1/public/settings')->json('data.services.cards');
        $this->assertSame(['school', 'page', 'page'], array_column($public, 'kind'));
        $this->assertSame('Sign in', $public[1]['button_label_en']);
        $this->withHeader('X-Tenant', 'other')->getJson('/api/v1/public/settings')->assertJsonPath('data.services', null);

        $this->deleteJson("/api/v1/platform/website/services/cards/{$sms}")->assertNoContent();
        $this->deleteJson('/api/v1/platform/website/services/cards/nope')->assertNotFound();
        $this->assertCount(3, $this->getJson('/api/v1/platform/website/services')->json('data.cards'));
        foreach (['card_created', 'card_updated', 'card_deleted', 'switched'] as $what) {
            $this->assertDatabaseHas('bo_audit_logs', ['action' => "website.services.{$what}", 'tenant_id' => $this->newtech->id]);
        }
    }

    public function test_a_card_can_open_a_web_address(): void
    {
        $this->manager();

        $this->addCard(['kind' => 'link', 'title_en' => 'Online shop'])->assertUnprocessable()->assertJsonValidationErrors('link_url');
        $id = $this->addCard(['kind' => 'link', 'title_en' => 'Online shop', 'slug' => 'ignored', 'link_url' => 'https://shop.example.com'])
            ->assertCreated()->assertJsonPath('data.kind', 'link')->assertJsonPath('data.link_url', 'https://shop.example.com')->assertJsonPath('data.slug', '')->json('data.id');

        // Turned into its own page, the address goes and a page address comes.
        $this->post("/api/v1/platform/website/services/cards/{$id}", ['_method' => 'PUT', 'kind' => 'page', 'status' => 'active', 'title_en' => 'Online shop', 'link_url' => 'https://shop.example.com'])
            ->assertOk()->assertJsonPath('data.link_url', '')->assertJsonPath('data.slug', 'online-shop');
    }

    public function test_one_card_can_open_the_downloads_page(): void
    {
        $this->manager();

        $id = $this->addCard(['kind' => 'downloads', 'title_en' => 'Downloads', 'slug' => 'ignored'])
            ->assertCreated()->assertJsonPath('data.kind', 'downloads')->assertJsonPath('data.slug', '')->json('data.id');
        $this->addCard(['kind' => 'downloads', 'title_en' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        // Changing that same card is fine.
        $this->post("/api/v1/platform/website/services/cards/{$id}", ['_method' => 'PUT', 'kind' => 'downloads', 'status' => 'active', 'title_en' => 'Files'])->assertOk();
    }

    public function test_the_first_version_is_read_as_two_cards(): void
    {
        $this->manager();
        $this->newtech->update(['settings' => ['services' => [
            'enabled' => true,
            'computer_school' => ['title_en' => 'Computer School', 'image_path' => null],
            'sms' => ['title_en' => 'SMS', 'heading_en' => 'Run it', 'sign_in_url' => 'https://erp.newtechkh.com/login', 'image_path' => null],
        ]]]);

        $cards = $this->getJson('/api/v1/platform/website/services')->assertOk()->json('data.cards');
        $this->assertSame(['school', 'page'], array_column($cards, 'kind'));
        $this->assertSame('school-management-system', $cards[1]['slug']);
        $this->assertSame('https://erp.newtechkh.com/login', $cards[1]['button_url']);
        $this->assertSame('Sign in', $cards[1]['button_label_en']);

        // The next change stores the new shape.
        $this->putJson('/api/v1/platform/website/services/settings', ['enabled' => true])->assertOk();
        $this->assertCount(2, $this->newtech->fresh()->setting('services.cards'));
    }

    public function test_cards_validate_and_need_manage(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.website.read']));
        $this->addCard(['title_en' => 'X'])->assertNotFound();
        $this->putJson('/api/v1/platform/website/services/settings', ['enabled' => true])->assertNotFound();

        $this->manager();
        $this->postJson('/api/v1/platform/website/services/cards', [])->assertUnprocessable()->assertJsonValidationErrors(['kind', 'status']);
        $this->addCard(['title_en' => 'X', 'button_url' => 'not a url'])->assertUnprocessable()->assertJsonValidationErrors('button_url');
        $this->addCard(['title_en' => 'X', 'slug' => 'Has Spaces'])->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    // ── Behaviour carried over from the school app's own tests (stage 7c) ──

    public function test_a_non_image_is_refused_and_an_edit_without_a_new_picture_keeps_it(): void
    {
        $this->manager();

        $this->post('/api/v1/platform/website/slides', ['image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('image');

        $id = $this->post('/api/v1/platform/website/gallery', ['image' => UploadedFile::fake()->image('a.jpg')])->assertCreated()->json('data.id');
        $path = GalleryImage::query()->withoutGlobalScopes()->findOrFail($id)->image_path;

        $this->post("/api/v1/platform/website/gallery/{$id}", ['_method' => 'PUT', 'caption' => 'Lab day'])->assertOk()->assertJsonPath('data.caption', 'Lab day');
        $this->assertSame($path, GalleryImage::query()->withoutGlobalScopes()->findOrFail($id)->image_path);

        // Removing is a soft delete: the picture stays until a force delete.
        $this->deleteJson("/api/v1/platform/website/gallery/{$id}")->assertNoContent();
        Storage::disk('public')->assertExists($path);
    }

    public function test_folders_go_two_levels_deep_and_runnable_files_are_refused(): void
    {
        $this->manager();

        $top = $this->postJson('/api/v1/platform/website/download-folders', ['name' => 'Photoshop'])->assertCreated()->json('data.id');
        $sub = $this->postJson('/api/v1/platform/website/download-folders', ['name' => '2026', 'parent_id' => $top])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/platform/website/download-folders', ['name' => 'Too deep', 'parent_id' => $sub])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->post("/api/v1/platform/website/download-folders/{$top}/files", ['file' => UploadedFile::fake()->create('page.html', 1, 'text/html')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_removing_a_folder_removes_its_sub_folders_and_files_from_storage(): void
    {
        $this->manager();

        $top = $this->postJson('/api/v1/platform/website/download-folders', ['name' => 'Forms'])->json('data.id');
        $sub = $this->postJson('/api/v1/platform/website/download-folders', ['name' => '2026', 'parent_id' => $top])->json('data.id');
        $this->post("/api/v1/platform/website/download-folders/{$top}/files", ['file' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')])->assertCreated();
        $this->post("/api/v1/platform/website/download-folders/{$sub}/files", ['file' => UploadedFile::fake()->create('b.pdf', 5, 'application/pdf')])->assertCreated();
        $paths = \App\Models\DownloadFile::query()->withoutGlobalScopes()->pluck('file_path')->all();
        $this->assertCount(2, $paths);

        $this->deleteJson("/api/v1/platform/website/download-folders/{$top}")->assertNoContent();

        $this->assertSame(0, $this->countIn(DownloadFolder::class, $this->newtech));
        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_about_stats_must_be_four_and_saving_keeps_the_schools_other_settings(): void
    {
        $this->manager();
        $this->newtech->update(['settings' => ['public_site' => ['hero_title' => 'Keep me']]]);

        $this->postJson('/api/v1/platform/website/about', ['stats' => [['value' => '1', 'label' => 'x']]])
            ->assertUnprocessable()->assertJsonValidationErrors('stats');

        $this->postJson('/api/v1/platform/website/about', ['history_title' => 'History'])->assertOk();
        $settings = $this->newtech->fresh()->settings;
        $this->assertSame('Keep me', $settings['public_site']['hero_title']);
        $this->assertSame('History', $settings['about']['history_title']);
    }

    public function test_no_website_school_is_a_clear_error_and_nothing_is_written(): void
    {
        $this->manager();
        config(['platform.website_school' => 'missing']);

        $this->getJson('/api/v1/platform/website/slides')->assertStatus(409)->assertJsonPath('error.code', 'WEBSITE_SCHOOL_MISSING');
        $this->post('/api/v1/platform/website/slides', ['image' => UploadedFile::fake()->image('a.jpg')])->assertStatus(409);
        $this->assertSame(0, HomeSlide::query()->withoutGlobalScopes()->count());
    }
}
