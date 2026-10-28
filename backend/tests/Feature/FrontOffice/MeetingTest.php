<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Models\Staff;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class MeetingTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function desk(array $extra = []): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::MEETINGS_VIEW, Permissions::MEETINGS_CREATE, Permissions::MEETINGS_UPDATE, ...$extra,
        ]);
    }

    private function staffWithUser(): Staff
    {
        $user = User::factory()->forTenant($this->tenant)->create();

        return Staff::factory()->withUser($user)->create();
    }

    private function arrange(array $overrides = []): array
    {
        return $this->postJson('/api/v1/meetings', [
            'title' => 'Term planning',
            'meeting_date' => now()->addDays(3)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:30',
            'location' => 'Room A',
            'agenda' => "1. Timetable\n2. Exams",
            'guests' => "Mr. Dara (parent rep)",
            ...$overrides,
        ])->assertCreated()->json('data');
    }

    private function notified(Staff $staff, string $type): int
    {
        return UserNotification::query()->where('recipient_id', $staff->user_id)->where('type', $type)->count();
    }

    public function test_a_meeting_is_arranged_with_participants_who_are_notified(): void
    {
        $this->desk();
        $a = $this->staffWithUser();
        $b = $this->staffWithUser();

        $meeting = $this->arrange(['participant_ids' => [$a->id, $b->id], 'organizer_staff_id' => $a->id]);

        $this->assertSame('scheduled', $meeting['status']);
        $this->assertSame('09:00', $meeting['start_time']);
        $this->assertCount(2, $meeting['participants']);
        $this->assertSame($a->fullName(), $meeting['organizer']['name']);
        $this->assertSame(1, $this->notified($a, NotificationType::MEETING_INVITED));
        $this->assertSame(1, $this->notified($b, NotificationType::MEETING_INVITED));
    }

    public function test_upcoming_and_past_lists_and_summary(): void
    {
        $this->desk();
        $this->arrange();
        $this->arrange(['title' => 'Old one', 'meeting_date' => now()->subDays(5)->toDateString()]);
        $this->arrange(['title' => 'Today', 'meeting_date' => now()->toDateString()]);

        $this->getJson('/api/v1/meetings?when=upcoming')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.title', 'Today');
        $this->getJson('/api/v1/meetings?when=past')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Old one');
        $this->getJson('/api/v1/meetings?search=planning')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/meetings/summary')->assertOk()
            ->assertJsonPath('data.today', 1)
            ->assertJsonPath('data.upcoming', 2);
    }

    public function test_adding_people_invites_only_the_new_ones_and_moving_it_tells_the_rest(): void
    {
        $this->desk();
        $a = $this->staffWithUser();
        $b = $this->staffWithUser();
        $meeting = $this->arrange(['participant_ids' => [$a->id]]);

        $this->putJson("/api/v1/meetings/{$meeting['id']}", [
            'participant_ids' => [$a->id, $b->id],
            'start_time' => '14:00',
            'end_time' => '15:00',
        ])->assertOk()->assertJsonCount(2, 'data.participants');

        $this->assertSame(1, $this->notified($a, NotificationType::MEETING_INVITED));
        $this->assertSame(1, $this->notified($a, NotificationType::MEETING_UPDATED));
        $this->assertSame(1, $this->notified($b, NotificationType::MEETING_INVITED));
        $this->assertSame(0, $this->notified($b, NotificationType::MEETING_UPDATED));
    }

    public function test_the_meeting_note_is_kept_and_it_can_be_completed_or_cancelled(): void
    {
        $this->desk();
        $a = $this->staffWithUser();
        $meeting = $this->arrange(['participant_ids' => [$a->id]]);

        $this->putJson("/api/v1/meetings/{$meeting['id']}", ['minutes' => 'Agreed exam week.', 'status' => 'completed'])
            ->assertOk()->assertJsonPath('data.minutes', 'Agreed exam week.')->assertJsonPath('data.status', 'completed');
        $this->assertSame(0, $this->notified($a, NotificationType::MEETING_CANCELLED));

        $other = $this->arrange(['participant_ids' => [$a->id]]);
        $this->putJson("/api/v1/meetings/{$other['id']}", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(1, $this->notified($a, NotificationType::MEETING_CANCELLED));
    }

    public function test_end_time_must_be_after_start_and_delete_needs_permission(): void
    {
        $this->desk();
        $this->postJson('/api/v1/meetings', [
            'title' => 'Bad', 'meeting_date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '09:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('end_time');

        $meeting = $this->arrange();
        $this->deleteJson("/api/v1/meetings/{$meeting['id']}")->assertForbidden();

        $this->desk([Permissions::MEETINGS_DELETE]);
        $this->deleteJson("/api/v1/meetings/{$meeting['id']}")->assertNoContent();
    }

    public function test_meetings_show_on_the_school_calendar_for_people_who_can_see_them(): void
    {
        $this->desk();
        $date = now()->addDays(3)->toDateString();
        $kept = $this->arrange(['meeting_date' => $date]);
        $dropped = $this->arrange(['title' => 'Called off', 'meeting_date' => $date]);
        $this->putJson("/api/v1/meetings/{$dropped['id']}", ['status' => 'cancelled'])->assertOk();

        $range = '/api/v1/school-calendar?from='.now()->toDateString().'&to='.now()->addDays(10)->toDateString();

        $this->actingAsAdminWithPermissions([Permissions::SCHOOL_CALENDAR_VIEW, Permissions::MEETINGS_VIEW]);
        $this->getJson($range)->assertOk()
            ->assertJsonCount(1, 'data.meetings')
            ->assertJsonPath('data.meetings.0.id', $kept['id'])
            ->assertJsonPath('data.meetings.0.date', $date)
            ->assertJsonPath('data.meetings.0.start_time', '09:00');

        // Calendar only (e.g. a student or parent role): no meetings.
        $this->actingAsAdminWithPermissions([Permissions::SCHOOL_CALENDAR_VIEW]);
        $this->getJson($range)->assertOk()->assertJsonCount(0, 'data.meetings');
    }

    public function test_view_only_cannot_arrange(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::MEETINGS_VIEW]);

        $this->getJson('/api/v1/meetings')->assertOk();
        $this->postJson('/api/v1/meetings', ['title' => 'x', 'meeting_date' => now()->toDateString(), 'start_time' => '09:00'])->assertForbidden();
    }
}
