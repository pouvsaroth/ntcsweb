<?php

declare(strict_types=1);

namespace Tests\Feature\Hrm;

use App\Models\Staff;
use App\Models\TeacherQualification;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** HRM > Professional Development: Training Calendar, Training Records, Certificates. */
class TrainingTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const BASE = '/api/v1/professional-development';

    private function manager(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROFESSIONAL_DEVELOPMENT_VIEW, Permissions::PROFESSIONAL_DEVELOPMENT_MANAGE]);
    }

    private function staffWithUser(): Staff
    {
        return Staff::factory()->withUser(User::factory()->forTenant($this->tenant)->create())->create();
    }

    private function plan(array $overrides = []): array
    {
        return $this->postJson(self::BASE.'/trainings', [
            'title' => 'Active learning workshop',
            'category' => 'Pedagogy',
            'trainer' => 'Ministry of Education',
            'location' => 'Hall A',
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'hours' => 12,
            ...$overrides,
        ])->assertCreated()->json('data');
    }

    public function test_a_planned_training_assigns_staff_who_are_notified(): void
    {
        $this->manager();
        $a = $this->staffWithUser();
        $b = $this->staffWithUser();

        $training = $this->plan(['participant_ids' => [$a->id, $b->id]]);

        $this->assertSame('planned', $training['status']);
        $this->assertCount(2, $training['participants']);
        $this->assertSame('registered', $training['participants'][0]['attendance']);
        $this->assertSame(1, UserNotification::query()->where('recipient_id', $a->user_id)->where('type', NotificationType::TRAINING_ASSIGNED)->count());

        $month = now()->addDays(5)->format('Y-m');
        $this->getJson(self::BASE."/trainings/calendar?month={$month}")->assertOk()->assertJsonPath('data.0.title', 'Active learning workshop');
        $this->getJson(self::BASE.'/trainings/summary')->assertOk()->assertJsonPath('data.upcoming', 1);
    }

    public function test_a_past_training_is_recorded_as_attended_without_notifying(): void
    {
        $this->manager();
        $a = $this->staffWithUser();

        $training = $this->plan([
            'status' => 'completed', 'provider_type' => 'external',
            'start_date' => now()->subDays(30)->toDateString(), 'end_date' => now()->subDays(30)->toDateString(),
            'participant_ids' => [$a->id],
        ]);

        $this->assertSame('attended', $training['participants'][0]['attendance']);
        $this->assertSame(0, UserNotification::query()->where('recipient_id', $a->user_id)->count());

        $this->getJson(self::BASE."/records/totals?staff_id={$a->id}")->assertOk()
            ->assertJsonPath('data.attended', 1)
            ->assertJsonPath('data.hours', 12);
    }

    public function test_attendance_and_results_are_taken_for_the_whole_training(): void
    {
        $this->manager();
        $a = $this->staffWithUser();
        $b = $this->staffWithUser();
        $training = $this->plan(['participant_ids' => [$a->id, $b->id]]);
        [$pa, $pb] = $training['participants'];

        $this->putJson(self::BASE."/trainings/{$training['id']}/attendance", ['rows' => [
            ['id' => $pa['id'], 'attendance' => 'attended', 'result' => 'passed', 'score' => 85],
            ['id' => $pb['id'], 'attendance' => 'absent'],
        ]])->assertOk();

        $rows = $this->getJson(self::BASE."/records?staff_id={$a->id}")->assertOk()->json('data');
        $this->assertSame('passed', $rows[0]['result']);
        $this->assertEquals(85, $rows[0]['score']);
        $this->assertSame('Active learning workshop', $rows[0]['training']['title']);
    }

    public function test_a_certificate_is_numbered_kept_in_qualification_and_revoked(): void
    {
        Storage::fake('local');
        $this->manager();
        $a = $this->staffWithUser();
        $training = $this->plan(['participant_ids' => [$a->id]]);
        $participantId = $training['participants'][0]['id'];
        $this->putJson(self::BASE."/trainings/{$training['id']}", ['status' => 'completed'])->assertOk();
        $this->putJson(self::BASE."/trainings/{$training['id']}/attendance", ['rows' => [['id' => $participantId, 'attendance' => 'attended']]])->assertOk();

        $this->getJson(self::BASE.'/certificates?state=pending')->assertOk()->assertJsonCount(1, 'data');

        $issued = $this->post(self::BASE."/records/{$participantId}/certificate", [
            'issued_on' => '2026-10-30',
            'file' => UploadedFile::fake()->create('cert.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.certificate');

        $this->assertSame('CERT-2026-0001', $issued['no']);
        $this->assertTrue($issued['has_file']);
        $this->getJson(self::BASE.'/certificates?state=pending')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE.'/certificates')->assertOk()->assertJsonCount(1, 'data');

        $qualification = TeacherQualification::query()->where('staff_id', $a->id)->sole();
        $this->assertSame('certificate', $qualification->type);
        $this->assertSame('Active learning workshop', $qualification->title);
        $this->assertSame(2026, $qualification->year);

        // Renaming the training renames the qualification.
        $this->putJson(self::BASE."/trainings/{$training['id']}", ['title' => 'Active learning (advanced)'])->assertOk();
        $this->assertSame('Active learning (advanced)', $qualification->refresh()->title);

        $this->get(self::BASE."/records/{$participantId}/certificate/file")->assertOk();

        $this->deleteJson(self::BASE."/records/{$participantId}/certificate")->assertNoContent();
        $this->assertSame(0, TeacherQualification::query()->where('staff_id', $a->id)->count());
        $this->getJson(self::BASE.'/certificates')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_removing_someone_from_a_training_drops_their_certificate_qualification(): void
    {
        $this->manager();
        $a = $this->staffWithUser();
        $training = $this->plan(['participant_ids' => [$a->id], 'status' => 'completed']);
        $participantId = $training['participants'][0]['id'];
        $this->postJson(self::BASE."/records/{$participantId}/certificate", ['issued_on' => '2026-10-30', 'certificate_no' => 'MOE-77'])->assertOk()
            ->assertJsonPath('data.certificate.no', 'MOE-77');
        $this->assertSame(1, TeacherQualification::query()->where('staff_id', $a->id)->count());

        $this->putJson(self::BASE."/trainings/{$training['id']}", ['participant_ids' => []])->assertOk()->assertJsonCount(0, 'data.participants');
        $this->assertSame(0, TeacherQualification::query()->where('staff_id', $a->id)->count());
    }

    public function test_view_only_cannot_change_anything(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROFESSIONAL_DEVELOPMENT_VIEW]);

        $this->getJson(self::BASE.'/trainings')->assertOk();
        $this->postJson(self::BASE.'/trainings', ['title' => 'X', 'start_date' => '2026-11-01', 'end_date' => '2026-11-01'])->assertForbidden();
    }

    public function test_no_permission_cannot_see_trainings(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson(self::BASE.'/trainings')->assertForbidden();
        $this->getJson(self::BASE.'/records')->assertForbidden();
    }
}
