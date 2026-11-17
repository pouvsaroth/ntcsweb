<?php

declare(strict_types=1);

namespace Tests\Feature\StaffAttendance;

use App\Models\Geofence;
use App\Models\SchoolNetwork;
use App\Models\Staff;
use App\Models\StaffGeofenceRule;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Attendance & Time > Geofences > School Wi-Fi: on the school's
 * internet address a check-in / out needs no location; off it, the zones
 * decide as before — whichever works.
 */
class SchoolNetworkTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const MANAGE = [Permissions::STAFF_ATTENDANCE_VIEW, Permissions::GEOFENCES_CREATE, Permissions::GEOFENCES_UPDATE, Permissions::GEOFENCES_DELETE];

    private const LAT = 11.5564;

    private const LNG = 104.9282;

    private const SCHOOL_IP = '203.0.113.7';

    private const HOME_IP = '198.51.100.20';

    private function circle(): Geofence
    {
        return Geofence::query()->create(['name' => 'Newtech HQ', 'shape' => 'circle', 'latitude' => self::LAT, 'longitude' => self::LNG, 'radius_m' => 100, 'is_active' => true]);
    }

    private function wifi(string $range = self::SCHOOL_IP, bool $active = true): SchoolNetwork
    {
        return SchoolNetwork::query()->create(['name' => 'Newtech Wi-Fi', 'ip_range' => $range, 'is_active' => $active]);
    }

    private function staffMember(array $permissions = []): Staff
    {
        $user = $this->actingAsAdminWithPermissions($permissions);

        return Staff::factory()->create(['user_id' => $user->id]);
    }

    private function onIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    public function test_networks_are_added_edited_and_deleted_with_geofence_permissions(): void
    {
        $this->actingAsAdminWithPermissions(self::MANAGE);

        $this->onIp(self::SCHOOL_IP)->getJson('/api/v1/school-networks/current')->assertOk()
            ->assertJsonPath('data.ip', self::SCHOOL_IP)
            ->assertJsonPath('data.ip_range', self::SCHOOL_IP)
            ->assertJsonPath('data.network', null);

        $id = $this->onIp(self::SCHOOL_IP)->postJson('/api/v1/school-networks', ['name' => 'Newtech Wi-Fi', 'ip_range' => self::SCHOOL_IP])
            ->assertCreated()->assertJsonPath('data.is_current', true)->json('data.id');
        $this->onIp(self::SCHOOL_IP)->getJson('/api/v1/school-networks/current')->assertOk()->assertJsonPath('data.network.id', $id);

        $this->postJson('/api/v1/school-networks', ['name' => 'Bad', 'ip_range' => 'not-an-ip'])->assertUnprocessable()->assertJsonValidationErrors('ip_range');
        // Too wide a range.
        $this->postJson('/api/v1/school-networks', ['name' => 'Bad', 'ip_range' => '10.0.0.0/8'])->assertUnprocessable()->assertJsonValidationErrors('ip_range');
        $this->postJson('/api/v1/school-networks', ['name' => 'Campus 2', 'ip_range' => '203.0.113.0/29'])->assertCreated();

        $this->putJson("/api/v1/school-networks/{$id}", ['name' => 'Newtech Wi-Fi', 'ip_range' => self::SCHOOL_IP, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v1/school-networks')->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson("/api/v1/school-networks/{$id}")->assertNoContent();
    }

    public function test_viewing_needs_attendance_view_and_changing_needs_geofence_permissions(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_ATTENDANCE_VIEW]);
        $network = $this->wifi();

        $this->getJson('/api/v1/school-networks')->assertOk();
        $this->postJson('/api/v1/school-networks', ['name' => 'X', 'ip_range' => self::HOME_IP])->assertForbidden();
        $this->putJson("/api/v1/school-networks/{$network->id}", ['name' => 'X', 'ip_range' => self::HOME_IP])->assertForbidden();
        $this->deleteJson("/api/v1/school-networks/{$network->id}")->assertForbidden();
    }

    public function test_ipv6_wifi_is_saved_as_its_64_and_matches_every_phone_on_it(): void
    {
        $this->assertSame('2001:db8:1:2::/64', SchoolNetwork::rangeFor('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));

        $this->staffMember();
        $this->circle();
        $this->wifi('2001:db8:1:2::/64');

        $this->onIp('2001:db8:1:2:1111:2222:3333:4444')->postJson('/api/v1/my-staff-attendance/check-in')->assertOk()
            ->assertJsonPath('data.check_in_via', 'wifi');
    }

    public function test_on_school_wifi_check_in_and_out_need_no_location(): void
    {
        $this->staffMember();
        $this->circle();
        $this->wifi();

        $this->onIp(self::SCHOOL_IP)->getJson('/api/v1/my-staff-attendance/today')->assertOk()
            ->assertJsonPath('data.geofence.required', true)
            ->assertJsonPath('data.geofence.wifi', 'Newtech Wi-Fi')
            ->assertJsonPath('data.geofence.wifi_set_up', true);

        // No location at all — and a location 1.1 km away — both fine on the Wi-Fi.
        $this->onIp(self::SCHOOL_IP)->postJson('/api/v1/my-staff-attendance/check-in')->assertOk()
            ->assertJsonPath('data.check_in_via', 'wifi')
            ->assertJsonPath('data.check_in_geofence', 'Newtech Wi-Fi');
        $this->onIp(self::SCHOOL_IP)->postJson('/api/v1/my-staff-attendance/check-out', ['latitude' => self::LAT + 0.01, 'longitude' => self::LNG])->assertOk()
            ->assertJsonPath('data.check_out_via', 'wifi');
    }

    public function test_off_the_wifi_location_still_works_and_neither_is_refused(): void
    {
        $this->staffMember();
        $this->circle();
        $this->wifi();

        $this->onIp(self::HOME_IP)->getJson('/api/v1/my-staff-attendance/today')->assertOk()->assertJsonPath('data.geofence.wifi', null);

        $none = $this->onIp(self::HOME_IP)->postJson('/api/v1/my-staff-attendance/check-in')->assertUnprocessable()->json('errors.check_in.0');
        $this->assertStringContainsString('Wi-Fi', $none);
        $far = $this->onIp(self::HOME_IP)->postJson('/api/v1/my-staff-attendance/check-in', ['latitude' => self::LAT + 0.01, 'longitude' => self::LNG])
            ->assertUnprocessable()->json('errors.check_in.0');
        $this->assertStringContainsString('Newtech HQ', $far);
        $this->assertStringContainsString('Wi-Fi', $far);

        $this->onIp(self::HOME_IP)->postJson('/api/v1/my-staff-attendance/check-in', ['latitude' => self::LAT, 'longitude' => self::LNG])->assertOk()
            ->assertJsonPath('data.check_in_via', 'location')
            ->assertJsonPath('data.check_in_geofence', 'Newtech HQ');
    }

    public function test_a_turned_off_network_does_not_count(): void
    {
        $this->staffMember();
        $this->circle();
        $this->wifi(active: false);

        $this->onIp(self::SCHOOL_IP)->postJson('/api/v1/my-staff-attendance/check-in')->assertUnprocessable()->assertJsonValidationErrors('check_in');
    }

    public function test_wifi_alone_makes_places_needed(): void
    {
        $this->staffMember();
        $this->wifi();

        $this->onIp(self::HOME_IP)->getJson('/api/v1/my-staff-attendance/today')->assertOk()
            ->assertJsonPath('data.geofence.required', true)
            ->assertJsonPath('data.geofence.zones', []);
        $this->onIp(self::HOME_IP)->postJson('/api/v1/my-staff-attendance/check-in', ['latitude' => self::LAT, 'longitude' => self::LNG])
            ->assertUnprocessable()->assertJsonValidationErrors('check_in');
        $this->onIp(self::SCHOOL_IP)->postJson('/api/v1/my-staff-attendance/check-in')->assertOk()->assertJsonPath('data.check_in_via', 'wifi');
    }

    public function test_anywhere_staff_are_not_affected(): void
    {
        $staff = $this->staffMember();
        $this->circle();
        $this->wifi();
        StaffGeofenceRule::query()->create(['staff_id' => $staff->id, 'mode' => StaffGeofenceRule::ANYWHERE]);

        $this->onIp(self::HOME_IP)->postJson('/api/v1/my-staff-attendance/check-in')->assertOk()->assertJsonPath('data.check_in_via', null);
    }
}
