<?php

declare(strict_types=1);

namespace Tests\Feature\Hrm;

use App\Models\Kpi;
use App\Models\Staff;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Roles step 3c: Performance, Professional Development and Teachers tabs
 * each have their own Add / Update / Delete instead of one manage.
 */
class PerformanceDevelopmentTeacherPermissionsSplitTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_performance_tabs_are_separate(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PERFORMANCE_VIEW, Permissions::PERFORMANCE_KPIS_CREATE]);
        $this->postJson('/api/v1/kpis', ['code' => 'PR', 'name' => 'Pass rate'])->assertCreated();
        $this->postJson('/api/v1/evaluation-forms', ['name' => 'Teacher evaluation', 'questions' => [['question' => 'X', 'type' => 'rating']]])->assertForbidden();
        $kpi = Kpi::query()->firstOrFail();
        $this->putJson("/api/v1/kpis/{$kpi->id}", ['name' => 'Pass %'])->assertForbidden();
        $this->deleteJson("/api/v1/kpis/{$kpi->id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PERFORMANCE_VIEW, Permissions::PERFORMANCE_KPIS_UPDATE]);
        $this->putJson("/api/v1/kpis/{$kpi->id}", ['name' => 'Pass %'])->assertOk();

        $this->actingAsAdminWithPermissions([Permissions::PERFORMANCE_VIEW, Permissions::PERFORMANCE_KPIS_DELETE]);
        $this->deleteJson("/api/v1/kpis/{$kpi->id}")->assertNoContent();
    }

    public function test_observation_forms_follow_the_classroom_observation_tab(): void
    {
        $form = ['name' => 'Lesson observation', 'criteria' => [['section' => 'Planning', 'criterion' => 'Clear lesson objective']]];
        $this->actingAsAdminWithPermissions([Permissions::PROFESSIONAL_DEVELOPMENT_VIEW, Permissions::TRAININGS_CREATE]);
        $this->postJson('/api/v1/professional-development/observation-forms', $form)->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PROFESSIONAL_DEVELOPMENT_VIEW, Permissions::CLASSROOM_OBSERVATIONS_CREATE]);
        $this->postJson('/api/v1/professional-development/observation-forms', $form)->assertCreated();
    }

    public function test_teacher_tabs_are_separate(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TEACHERS_VIEW, Permissions::TEACHER_DOCUMENTS_CREATE]);
        $staff = Staff::factory()->create();
        $this->postJson('/api/v1/teachers/qualifications', ['staff_id' => $staff->id, 'type' => 'skill', 'title' => 'Microsoft Office'])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::TEACHERS_VIEW, Permissions::TEACHER_QUALIFICATIONS_CREATE]);
        // Allowed past the permission check (a non-teacher is then refused by validation).
        $this->assertNotSame(403, $this->postJson('/api/v1/teachers/qualifications', ['staff_id' => $staff->id, 'type' => 'skill', 'title' => 'Microsoft Office'])->status());
    }

    public function test_school_admin_has_every_new_permission_by_default(): void
    {
        $defaults = Permissions::defaultsForSystemRoles()['school-admin'];
        foreach ([
            Permissions::PERFORMANCE_KPIS_DELETE, Permissions::PERFORMANCE_REVIEWS_DELETE, Permissions::PERFORMANCE_PROMOTIONS_UPDATE,
            Permissions::TRAININGS_DELETE, Permissions::TRAINING_CERTIFICATES_DELETE, Permissions::CLASSROOM_OBSERVATIONS_DELETE,
            Permissions::COACHING_DELETE, Permissions::DEVELOPMENT_PLANS_DELETE, Permissions::TEACHER_QUALIFICATIONS_DELETE,
            Permissions::TEACHER_HOMEROOM_UPDATE, Permissions::TEACHER_DOCUMENTS_DELETE,
        ] as $slug) {
            $this->assertContains($slug, $defaults);
        }
    }
}
