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

class AppointmentVisitorTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function desk(array $extra = []): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::APPOINTMENTS_VIEW, Permissions::APPOINTMENTS_CREATE, Permissions::APPOINTMENTS_UPDATE,
            Permissions::VISITORS_VIEW, Permissions::VISITORS_CREATE, Permissions::VISITORS_UPDATE,
            ...$extra,
        ]);
    }

    private function host(): Staff
    {
        return Staff::factory()->withUser(User::factory()->forTenant($this->tenant)->create())->create();
    }

    private function book(array $overrides = []): array
    {
        return $this->postJson('/api/v1/appointments', [
            'visitor_name' => 'Chan Dara',
            'phone' => '012111222',
            'organization' => 'ABC Books',
            'purpose' => 'Textbook supply',
            'appointment_date' => now()->toDateString(),
            'start_time' => '10:00',
            ...$overrides,
        ])->assertCreated()->json('data');
    }

    private function notified(Staff $staff, string $type): int
    {
        return UserNotification::query()->where('recipient_id', $staff->user_id)->where('type', $type)->count();
    }

    public function test_booking_notifies_the_host_and_lists_today(): void
    {
        $this->desk();
        $host = $this->host();
        $this->book(['host_staff_id' => $host->id]);
        $this->book(['visitor_name' => 'Later', 'appointment_date' => now()->addDays(2)->toDateString()]);

        $this->assertSame(1, $this->notified($host, NotificationType::APPOINTMENT_BOOKED));
        $this->getJson('/api/v1/appointments?when=today')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.host.name', $host->fullName());
        $this->getJson('/api/v1/appointments?when=upcoming')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.visitor_name', 'Chan Dara');
        $this->getJson('/api/v1/appointments/summary')->assertOk()->assertJsonPath('data.waiting', 1)->assertJsonPath('data.upcoming', 1);
    }

    public function test_check_in_creates_the_visitor_and_check_out_completes_the_appointment(): void
    {
        $this->desk();
        $host = $this->host();
        $appointment = $this->book(['host_staff_id' => $host->id]);

        $visitor = $this->postJson("/api/v1/appointments/{$appointment['id']}/check-in", ['people_count' => 2, 'badge_no' => 'V-07'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Chan Dara')
            ->assertJsonPath('data.appointment_id', $appointment['id'])
            ->assertJsonPath('data.people_count', 2)
            ->json('data');
        $this->assertSame(1, $this->notified($host, NotificationType::VISITOR_ARRIVED));
        $this->getJson("/api/v1/appointments/{$appointment['id']}")->assertJsonPath('data.status', 'checked_in');

        // Not twice.
        $this->postJson("/api/v1/appointments/{$appointment['id']}/check-in")->assertUnprocessable();

        $this->getJson('/api/v1/visitors?on_site=1')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/visitors/{$visitor['id']}/check-out")->assertOk()->assertJsonPath('data.check_out_at', fn ($v) => $v !== null);
        $this->getJson("/api/v1/appointments/{$appointment['id']}")->assertJsonPath('data.status', 'completed');
        $this->getJson('/api/v1/visitors?on_site=1')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/visitors/{$visitor['id']}/check-out")->assertUnprocessable();
    }

    public function test_a_walk_in_goes_straight_to_the_visitor_log(): void
    {
        $this->desk();
        $this->postJson('/api/v1/visitors', ['name' => 'Walk In', 'purpose' => 'Ask about fees', 'people_count' => 3])
            ->assertCreated()->assertJsonPath('data.appointment_id', null)->assertJsonPath('data.check_out_at', null);

        $this->getJson('/api/v1/visitors?date='.now()->toDateString())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/visitors/summary')->assertOk()
            ->assertJsonPath('data.today', 1)->assertJsonPath('data.people_today', 3)->assertJsonPath('data.on_site', 1);
    }

    public function test_status_rules_and_deleting_a_visit_reopens_the_appointment(): void
    {
        $this->desk([Permissions::VISITORS_DELETE]);
        $appointment = $this->book();

        $this->putJson("/api/v1/appointments/{$appointment['id']}", ['status' => 'completed'])->assertUnprocessable();
        $this->putJson("/api/v1/appointments/{$appointment['id']}", ['status' => 'no_show'])->assertOk()->assertJsonPath('data.status', 'no_show');
        $this->postJson("/api/v1/appointments/{$appointment['id']}/check-in")->assertUnprocessable();

        $this->putJson("/api/v1/appointments/{$appointment['id']}", ['status' => 'scheduled'])->assertOk();
        $visitor = $this->postJson("/api/v1/appointments/{$appointment['id']}/check-in")->assertCreated()->json('data');
        $this->deleteJson("/api/v1/visitors/{$visitor['id']}")->assertNoContent();
        $this->getJson("/api/v1/appointments/{$appointment['id']}")->assertJsonPath('data.status', 'scheduled');
    }

    public function test_permissions(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::VISITORS_VIEW]);
        $this->getJson('/api/v1/visitors')->assertOk();
        $this->getJson('/api/v1/front-office/staff-options')->assertOk();
        $this->getJson('/api/v1/appointments')->assertForbidden();
        $this->postJson('/api/v1/visitors', ['name' => 'x', 'purpose' => 'y'])->assertForbidden();

        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/v1/front-office/staff-options')->assertForbidden();
    }
}
