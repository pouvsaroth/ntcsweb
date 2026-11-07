<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\GradeLevel;
use App\Models\SchoolClass;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Roles step 2: School Setup, Assessment Setup, Exam Schedule and Timetable
 * have Add / Update / Delete of their own instead of one "manage".
 */
class AcademicPermissionsSplitTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_terms_add_update_delete_are_separate(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACADEMIC_TERMS_VIEW, Permissions::ACADEMIC_TERMS_CREATE]);
        $year = AcademicYear::factory()->create();
        $id = $this->postJson('/api/v1/academic-terms', ['academic_year_id' => $year->id, 'name' => 'Term 1'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/academic-terms/{$id}", ['academic_year_id' => $year->id, 'name' => 'Term A'])->assertForbidden();
        $this->deleteJson("/api/v1/academic-terms/{$id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::ACADEMIC_TERMS_UPDATE]);
        $this->putJson("/api/v1/academic-terms/{$id}", ['academic_year_id' => $year->id, 'name' => 'Term A'])->assertOk();
        $this->postJson('/api/v1/academic-terms', ['academic_year_id' => $year->id, 'name' => 'Term 2'])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::ACADEMIC_TERMS_DELETE]);
        $this->deleteJson("/api/v1/academic-terms/{$id}")->assertSuccessful();
    }

    public function test_grade_levels_one_click_set_is_an_add(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::GRADE_LEVELS_VIEW, Permissions::GRADE_LEVELS_UPDATE]);
        $this->postJson('/api/v1/grade-levels/defaults')->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::GRADE_LEVELS_CREATE]);
        $this->postJson('/api/v1/grade-levels/defaults')->assertOk();
    }

    public function test_a_new_curriculum_subject_is_an_add_and_changing_its_hours_an_update(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CURRICULUM_UPDATE]);
        $grade = GradeLevel::query()->create(['code' => 'G7', 'name' => 'Grade 7', 'level_order' => 7]);
        $math = Book::factory()->create(['title' => 'Mathematics']);
        $subject = ['grade_level_id' => $grade->id, 'book_id' => $math->id, 'hours_per_week' => 6];

        $this->putJson('/api/v1/curriculum/subjects', $subject)->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::CURRICULUM_CREATE]);
        $this->putJson('/api/v1/curriculum/subjects', $subject)->assertOk();
        $this->putJson('/api/v1/curriculum/subjects', ['hours_per_week' => 7] + $subject)->assertForbidden();
        $this->deleteJson('/api/v1/curriculum/subjects', $subject)->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::CURRICULUM_UPDATE]);
        $this->putJson('/api/v1/curriculum/subjects', ['hours_per_week' => 7] + $subject)->assertOk();

        $this->actingAsAdminWithPermissions([Permissions::CURRICULUM_DELETE]);
        $this->deleteJson('/api/v1/curriculum/subjects', $subject)->assertNoContent();
    }

    public function test_a_first_promotion_rule_is_an_add_and_changing_it_an_update(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROMOTION_RULES_UPDATE]);
        $this->putJson('/api/v1/promotion-rules', ['min_average' => 50, 'pass_mark' => 50])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PROMOTION_RULES_CREATE]);
        $this->putJson('/api/v1/promotion-rules', ['min_average' => 50, 'pass_mark' => 50])->assertOk();
        $this->putJson('/api/v1/promotion-rules', ['min_average' => 60, 'pass_mark' => 50])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PROMOTION_RULES_UPDATE]);
        $this->putJson('/api/v1/promotion-rules', ['min_average' => 60, 'pass_mark' => 50])->assertOk();
    }

    public function test_school_calendar_add_does_not_allow_delete(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::SCHOOL_CALENDAR_VIEW, Permissions::SCHOOL_CALENDAR_CREATE]);
        $id = $this->postJson('/api/v1/school-calendar', ['title' => 'School opens', 'type' => 'opening', 'start_date' => '2026-11-02'])->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/school-calendar/{$id}")->assertForbidden();
    }

    public function test_timetable_times_and_schedule_change_decisions_are_separate(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TIMETABLE_VIEW, Permissions::TIMETABLE_APPROVE]);
        $class = SchoolClass::factory()->create(['status' => SchoolClass::STATUS_ACTIVE]);
        $slot = ['class_id' => $class->id, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00'];

        $this->postJson('/api/v1/timetable/slots', $slot)->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::TIMETABLE_VIEW, Permissions::TIMETABLE_CREATE]);
        $id = $this->postJson('/api/v1/timetable/slots', $slot)->assertCreated()->json('data.slot.id');
        $this->deleteJson("/api/v1/timetable/slots/{$id}")->assertForbidden();
        // Not a decider: the change requests list is closed.
        $this->getJson('/api/v1/timetable/changes')->assertForbidden();

        // Approve alone opens everyone's requests.
        $this->actingAsAdminWithPermissions([Permissions::TIMETABLE_VIEW, Permissions::TIMETABLE_APPROVE]);
        $this->getJson('/api/v1/timetable/changes')->assertOk();

        $this->actingAsAdminWithPermissions([Permissions::TIMETABLE_VIEW, Permissions::TIMETABLE_DELETE]);
        $this->deleteJson("/api/v1/timetable/slots/{$id}")->assertSuccessful();
    }

    public function test_school_admin_has_the_new_permissions_by_default(): void
    {
        $defaults = Permissions::defaultsForSystemRoles()['school-admin'];
        foreach ([
            Permissions::ACADEMIC_TERMS_DELETE, Permissions::GRADE_LEVELS_DELETE, Permissions::CURRICULUM_DELETE,
            Permissions::SCHOOL_CALENDAR_DELETE, Permissions::PROMOTION_RULES_DELETE, Permissions::ASSESSMENT_DELETE,
            Permissions::EXAM_SCHEDULES_DELETE, Permissions::TIMETABLE_DELETE, Permissions::TIMETABLE_APPROVE, Permissions::TIMETABLE_REJECT,
        ] as $slug) {
            $this->assertContains($slug, $defaults);
        }
    }
}
