<?php

declare(strict_types=1);

namespace Tests\Feature\StaffAttendance;

use App\Models\Geofence;
use App\Models\Staff;
use App\Models\StaffGeofenceRule;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Attendance & Time > Geofences: while any zone is active, the app's
 * check-in / out needs a location inside one — a circle or a polygon —
 * unless the staff member's own rule says anywhere or only some zones.
 */
class GeofenceTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const MANAGE = [Permissions::STAFF_ATTENDANCE_VIEW, Permissions::GEOFENCES_CREATE, Permissions::GEOFENCES_UPDATE, Permissions::GEOFENCES_DELETE];

    // Around Independence Monument, Phnom Penh. 0.001° of latitude ≈ 111 m.
    private const LAT = 11.5564;

    private const LNG = 104.9282;

    private function circle(string $name = 'Main campus', int $radius = 100, bool $active = true): Geofence
    {
        return Geofence::query()->create(['name' => $name, 'shape' => 'circle', 'latitude' => self::LAT, 'longitude' => self::LNG, 'radius_m' => $radius, 'is_active' => $active]);
    }

    private function staffMember(array $permissions = []): Staff
    {
        $user = $this->actingAsAdminWithPermissions($permissions);

        return Staff::factory()->create(['user_id' => $user->id]);
    }

    public function test_zones_are_added_as_circles_and_polygons(): void
    {
        $this->actingAsAdminWithPermissions(self::MANAGE);

        $this->postJson('/api/v1/geofences', ['name' => 'Main campus', 'shape' => 'circle', 'latitude' => self::LAT, 'longitude' => self::LNG, 'radius_m' => 150])
            ->assertCreated()->assertJsonPath('data.radius_m', 150)->assertJsonPath('data.points', null);

        $square = [[11.5560, 104.9278], [11.5560, 104.9286], [11.5568, 104.9286], [11.5568, 104.9278]];
        $polygon = $this->postJson('/api/v1/geofences', ['name' => 'Campus 2', 'shape' => 'polygon', 'points' => $square])
            ->assertCreated()->assertJsonPath('data.radius_m', null)->json('data');
        $this->assertEqualsWithDelta(11.5564, $polygon['latitude'], 0.00001);
        $this->assertCount(4, $polygon['points']);

        $this->postJson('/api/v1/geofences', ['name' => 'Bad', 'shape' => 'polygon', 'points' => [[11.5, 104.9], [11.6, 104.9]]])
            ->assertUnprocessable()->assertJsonValidationErrors('points');
        $this->postJson('/api/v1/geofences', ['name' => 'Bad', 'shape' => 'circle'])
            ->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'radius_m']);

        $this->putJson("/api/v1/geofences/{$polygon['id']}", ['name' => 'Campus 2', 'shape' => 'polygon', 'points' => $square, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v1/geofences')->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson("/api/v1/geofences/{$polygon['id']}")->assertNoContent();
    }

    public function test_without_an_active_zone_anyone_checks_in_anywhere(): void
    {
        $this->staffMember();
        $this->circle(active: false);

        $this->getJson('/api/v1/my-staff-attendance/today')->assertOk()->assertJsonPath('data.geofence.required', false);
        $this->postJson('/api/v1/my-staff-attendance/check-in')->assertOk()->assertJsonPath('data.check_in_geofence', null);
    }

    public function test_check_in_and_out_need_a_location_inside_a_circle(): void
    {
        $this->staffMember();
        $this->circle(radius: 100);

        $this->getJson('/api/v1/my-staff-attendance/today')->assertOk()
            ->assertJsonPath('data.geofence.required', true)
            ->assertJsonPath('data.geofence.zones.0.name', 'Main campus');

        // No location → refused.
        $this->postJson('/api/v1/my-staff-attendance/check-in')->assertUnprocessable()->assertJsonValidationErrors('check_in');
        // ~333 m north → refused, with the distance.
        $far = $this->postJson('/api/v1/my-staff-attendance/check-in', ['latitude' => self::LAT + 0.003, 'longitude' => self::LNG])
            ->assertUnprocessable()->json('errors.check_in.0');
        $this->assertStringContainsString('Main campus', $far);
        $this->assertStringContainsString('234 m', $far);
        // ~110 m: 10 m outside, but the phone says ±20 m → counts.
        $this->postJson('/api/v1/my-staff-attendance/check-in', ['latitude' => self::LAT + 0.00099, 'longitude' => self::LNG, 'accuracy' => 20])
            ->assertOk()->assertJsonPath('data.check_in_geofence', 'Main campus');

        $this->postJson('/api/v1/my-staff-attendance/check-out', ['latitude' => self::LAT + 0.01, 'longitude' => self::LNG])
            ->assertUnprocessable()->assertJsonValidationErrors('check_out');
        $this->postJson('/api/v1/my-staff-attendance/check-out', ['latitude' => self::LAT, 'longitude' => self::LNG])
            ->assertOk()->assertJsonPath('data.check_out_geofence', 'Main campus');
    }

    public function test_a_polygon_counts_only_inside_its_corners(): void
    {
        $this->staffMember();
        // An L shape: the notch at the top right is outside.
        Geofence::query()->create(['name' => 'L block', 'shape' => 'polygon', 'latitude' => 11.5564, 'longitude' => 104.9282, 'points' => [
            [11.5560, 104.9278], [11.5560, 104.9290], [11.5564, 104.9290], [11.5564, 104.9284], [11.5568, 104.9284], [11.5568, 104.9278],
        ]]);

        $this->postJson('/api/v1/my-staff-attendance/check-in', ['latitude' => 11.5566, 'longitude' => 104.9288])
            ->assertUnprocessable()->assertJsonValidationErrors('check_in');
        $this->postJson('/api/v1/my-staff-attendance/check-in', ['latitude' => 11.5562, 'longitude' => 104.9288])
            ->assertOk()->assertJsonPath('data.check_in_geofence', 'L block');
    }

    public function test_a_staff_rule_allows_anywhere_or_only_some_zones(): void
    {
        $staff = $this->staffMember(self::MANAGE);
        $main = $this->circle('Main campus');
        $branch = Geofence::query()->create(['name' => 'Branch', 'shape' => 'circle', 'latitude' => self::LAT + 0.05, 'longitude' => self::LNG, 'radius_m' => 100]);

        // Only the Branch: the Main campus no longer counts.
        $this->putJson('/api/v1/geofence-rules', ['staff_id' => $staff->id, 'mode' => 'zones', 'geofence_ids' => [$branch->id]])
            ->assertOk()->assertJsonPath('data.geofence_ids', [$branch->id]);
        $this->getJson('/api/v1/my-staff-attendance/today')->assertJsonCount(1, 'data.geofence.zones')->assertJsonPath('data.geofence.zones.0.name', 'Branch');
        $this->postJson('/api/v1/my-staff-attendance/check-in', ['latitude' => self::LAT, 'longitude' => self::LNG])->assertUnprocessable();

        // Anywhere: no location needed.
        $rule = $this->putJson('/api/v1/geofence-rules', ['staff_id' => $staff->id, 'mode' => 'anywhere'])->assertOk()->json('data');
        $this->assertSame(1, StaffGeofenceRule::query()->count());
        $this->getJson('/api/v1/geofence-rules')->assertOk()->assertJsonPath('data.0.mode', 'anywhere');
        $this->postJson('/api/v1/my-staff-attendance/check-in')->assertOk();

        $this->putJson('/api/v1/geofence-rules', ['staff_id' => $staff->id, 'mode' => 'zones', 'geofence_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('geofence_ids');
        $this->deleteJson("/api/v1/geofence-rules/{$rule['id']}")->assertNoContent();
        $this->assertSame($main->id, $main->fresh()->id);
    }

    public function test_managing_zones_needs_the_permissions(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_ATTENDANCE_VIEW]);
        $zone = $this->circle();

        $this->getJson('/api/v1/geofences')->assertOk();
        $this->postJson('/api/v1/geofences', ['name' => 'X', 'shape' => 'circle', 'latitude' => 1, 'longitude' => 1, 'radius_m' => 50])->assertForbidden();
        $this->putJson("/api/v1/geofences/{$zone->id}", ['name' => 'X', 'shape' => 'circle', 'latitude' => 1, 'longitude' => 1, 'radius_m' => 50])->assertForbidden();
        $this->deleteJson("/api/v1/geofences/{$zone->id}")->assertForbidden();
        $this->putJson('/api/v1/geofence-rules', ['staff_id' => Staff::factory()->create()->id, 'mode' => 'anywhere'])->assertForbidden();
    }
}
