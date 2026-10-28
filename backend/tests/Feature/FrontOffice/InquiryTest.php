<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Models\Inquiry;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class InquiryTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function desk(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::INQUIRIES_VIEW, Permissions::INQUIRIES_CREATE, Permissions::INQUIRIES_UPDATE,
        ]);
    }

    private function record(array $overrides = []): array
    {
        return $this->postJson('/api/v1/inquiries', [
            'inquiry_date' => now()->toDateString(),
            'name' => 'Sok Dara',
            'gender' => 'male',
            'phone' => '012345678',
            'interested_in' => 'Word 2024',
            'source' => 'facebook',
            'next_follow_up_on' => now()->addDays(2)->toDateString(),
            ...$overrides,
        ])->assertCreated()->json('data');
    }

    public function test_an_inquiry_is_recorded_listed_and_searched(): void
    {
        $this->desk();
        $created = $this->record();
        $this->record(['name' => 'Chan Sophea', 'phone' => '099888777', 'source' => 'walk_in']);

        $this->assertSame('new', $created['status']);
        $this->getJson('/api/v1/inquiries')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/inquiries?search=Sophea')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.source', 'walk_in');
        $this->getJson('/api/v1/inquiries?filter[source]=facebook')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_follow_up_is_kept_and_moves_the_next_date(): void
    {
        $this->desk();
        $inquiry = $this->record();
        $next = now()->addWeek()->toDateString();

        $this->postJson("/api/v1/inquiries/{$inquiry['id']}/follow-ups", [
            'followed_up_on' => now()->toDateString(),
            'method' => 'call',
            'note' => 'Wants the evening class; will come Saturday.',
            'next_follow_up_on' => $next,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'following_up')
            ->assertJsonPath('data.next_follow_up_on', $next)
            ->assertJsonPath('data.follow_ups.0.method', 'call')
            ->assertJsonPath('data.follow_ups.0.created_by_name', $this->admin->name);

        $this->postJson("/api/v1/inquiries/{$inquiry['id']}/follow-ups", [
            'followed_up_on' => now()->toDateString(),
            'method' => 'message',
            'note' => 'Found another school.',
            'status' => 'not_interested',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'not_interested')
            ->assertJsonPath('data.next_follow_up_on', null)
            ->assertJsonCount(2, 'data.follow_ups');
    }

    public function test_due_lists_open_inquiries_whose_follow_up_date_has_come(): void
    {
        $this->desk();
        $this->record(['name' => 'Due today', 'next_follow_up_on' => now()->toDateString()]);
        $this->record(['name' => 'Overdue', 'next_follow_up_on' => now()->subDay()->toDateString()]);
        $this->record(['name' => 'Later', 'next_follow_up_on' => now()->addDays(3)->toDateString()]);
        Inquiry::query()->create([
            'inquiry_date' => now()->toDateString(), 'name' => 'Closed', 'phone' => '1', 'source' => 'phone',
            'status' => 'not_interested', 'next_follow_up_on' => now()->subDay()->toDateString(),
        ]);

        $this->getJson('/api/v1/inquiries?due=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/inquiries/summary')->assertOk()
            ->assertJsonPath('data.due', 2)
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.by_status.new', 3)
            ->assertJsonPath('data.by_status.not_interested', 1);
    }

    public function test_enrolling_links_the_student_and_closes_the_inquiry(): void
    {
        $this->desk();
        $inquiry = $this->record();
        $student = Student::factory()->create();

        $this->postJson("/api/v1/inquiries/{$inquiry['id']}/enroll", ['student_id' => $student->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'enrolled')
            ->assertJsonPath('data.student.id', $student->id)
            ->assertJsonPath('data.next_follow_up_on', null);
    }

    public function test_staff_options_list_active_staff_for_assigning(): void
    {
        $this->desk();
        $staff = \App\Models\Staff::factory()->create(['first_name' => 'Dara', 'last_name' => 'Sok']);

        $this->getJson('/api/v1/inquiries/staff-options')->assertOk()->assertJsonFragment(['id' => $staff->id, 'name' => 'Dara Sok']);
        $this->record(['assigned_staff_id' => $staff->id]);
        $this->getJson('/api/v1/inquiries')->assertOk()->assertJsonPath('data.0.assigned_staff.name', 'Dara Sok');
    }

    public function test_it_needs_the_permissions(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INQUIRIES_VIEW]);

        $this->getJson('/api/v1/inquiries')->assertOk();
        $this->postJson('/api/v1/inquiries', [
            'inquiry_date' => now()->toDateString(), 'name' => 'X', 'phone' => '1', 'source' => 'phone',
        ])->assertForbidden();

        $inquiry = Inquiry::query()->create(['inquiry_date' => now()->toDateString(), 'name' => 'X', 'phone' => '1', 'source' => 'phone']);
        $this->deleteJson("/api/v1/inquiries/{$inquiry->id}")->assertForbidden();
    }

    public function test_bad_values_are_rejected(): void
    {
        $this->desk();

        $this->postJson('/api/v1/inquiries', ['inquiry_date' => '28-10-2026', 'name' => '', 'phone' => '', 'source' => 'tv'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['inquiry_date', 'name', 'phone', 'source']);
    }
}
