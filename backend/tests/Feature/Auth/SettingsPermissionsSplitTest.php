<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Settings, eApprovals, Approval Flow and Help & Support: each menu has its
 * own View / Add / Update / Delete permissions (they used to share
 * tenant-settings.* or one "manage" permission).
 */
class SettingsPermissionsSplitTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_school_documents_need_their_own_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_VIEW, Permissions::TENANT_SETTINGS_UPDATE]);
        $this->getJson('/api/v1/settings/documents')->assertForbidden();
        $this->postJson('/api/v1/settings/documents', [])->assertForbidden();
    }

    public function test_viewing_school_documents_does_not_allow_changing_them(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::SCHOOL_DOCUMENTS_VIEW]);
        $this->getJson('/api/v1/settings/documents')->assertOk();
        $this->postJson('/api/v1/settings/documents', [])->assertForbidden();
    }

    public function test_school_documents_update_alone_is_enough_to_save(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::SCHOOL_DOCUMENTS_VIEW, Permissions::SCHOOL_DOCUMENTS_UPDATE]);
        $this->postJson('/api/v1/settings/documents', [])->assertOk();
    }

    public function test_telegram_groups_need_their_own_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $this->getJson('/api/v1/settings/notification-groups')->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::TELEGRAM_GROUPS_VIEW]);
        $this->getJson('/api/v1/settings/notification-groups')->assertOk();
        $this->postJson('/api/v1/settings/notification-groups', ['name' => 'Office'])->assertForbidden();
    }

    public function test_telegram_groups_add_update_delete_are_separate(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TELEGRAM_GROUPS_VIEW, Permissions::TELEGRAM_GROUPS_CREATE]);
        $id = $this->postJson('/api/v1/settings/notification-groups', ['name' => 'Office'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/settings/notification-groups/{$id}", ['name' => 'Front office'])->assertForbidden();
        $this->deleteJson("/api/v1/settings/notification-groups/{$id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::TELEGRAM_GROUPS_UPDATE]);
        $this->putJson("/api/v1/settings/notification-groups/{$id}", ['name' => 'Front office'])->assertOk();
        $this->deleteJson("/api/v1/settings/notification-groups/{$id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::TELEGRAM_GROUPS_DELETE]);
        $this->deleteJson("/api/v1/settings/notification-groups/{$id}")->assertSuccessful();
    }

    public function test_form_categories_add_update_delete_are_separate(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::FORM_CATEGORIES_VIEW, Permissions::FORM_CATEGORIES_CREATE]);
        $id = $this->postJson('/api/v1/form-categories', ['name' => 'HR'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/form-categories/{$id}", ['name' => 'People'])->assertForbidden();
        $this->deleteJson("/api/v1/form-categories/{$id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::FORM_CATEGORIES_UPDATE]);
        $this->putJson("/api/v1/form-categories/{$id}", ['name' => 'People'])->assertOk();
        $this->postJson('/api/v1/form-categories', ['name' => 'Other'])->assertForbidden();
    }

    public function test_approval_groups_and_flow_setting_have_their_own_permissions(): void
    {
        // Flow setting: View reads it (and the groups it picks from), Update changes it.
        $this->actingAsAdminWithPermissions([Permissions::APPROVAL_FLOWS_VIEW]);
        $this->getJson('/api/v1/approval-flows')->assertOk();
        $this->getJson('/api/v1/approval-groups')->assertOk();
        $this->putJson('/api/v1/approval-flows/leave_request', ['group_ids' => []])->assertForbidden();
        $this->postJson('/api/v1/approval-groups', ['name' => 'Heads'])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::APPROVAL_FLOWS_UPDATE]);
        $this->getJson('/api/v1/approval-flows')->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::APPROVAL_GROUPS_VIEW]);
        $this->getJson('/api/v1/approval-groups')->assertOk();
        $this->getJson('/api/v1/approval-flows')->assertForbidden();
    }

    public function test_languages_add_update_delete_are_separate(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LANGUAGES_VIEW]);
        $this->getJson('/api/v1/languages')->assertOk();
        $this->postJson('/api/v1/languages', ['code' => 'fr', 'name' => 'French'])->assertForbidden();
    }

    public function test_school_admin_has_the_new_permissions_by_default(): void
    {
        $defaults = Permissions::defaultsForSystemRoles()['school-admin'];
        foreach ([
            Permissions::SCHOOL_DOCUMENTS_VIEW, Permissions::SCHOOL_DOCUMENTS_UPDATE,
            Permissions::TELEGRAM_GROUPS_VIEW, Permissions::TELEGRAM_GROUPS_CREATE, Permissions::TELEGRAM_GROUPS_UPDATE, Permissions::TELEGRAM_GROUPS_DELETE,
            Permissions::LANGUAGES_VIEW, Permissions::LANGUAGES_CREATE, Permissions::LANGUAGES_UPDATE, Permissions::LANGUAGES_DELETE,
            Permissions::FORM_CATEGORIES_VIEW, Permissions::FORM_CATEGORIES_DELETE, Permissions::FORM_TEMPLATES_VIEW, Permissions::FORM_TEMPLATES_DELETE,
            Permissions::APPROVAL_GROUPS_VIEW, Permissions::APPROVAL_GROUPS_DELETE, Permissions::APPROVAL_FLOWS_VIEW, Permissions::APPROVAL_FLOWS_UPDATE,
            Permissions::HELP_TICKETS_CREATE,
        ] as $slug) {
            $this->assertContains($slug, $defaults);
        }
    }
}
