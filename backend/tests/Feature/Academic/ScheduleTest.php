<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\ClassSchedule;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_the_public_endpoint_lists_active_classes_with_their_weekly_slots(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        $teacher = Teacher::factory()->create(['name' => 'Mr. Dara']);
        $class = SchoolClass::factory()->withTeacher($teacher)->create(['name' => 'Web Development']);
        ClassSchedule::factory()->forClass($class)->onDay(1)->at('18:00:00', '20:00:00')->create();
        ClassSchedule::factory()->forClass($class)->onDay(3)->at('18:00:00', '20:00:00')->create();

        $response = $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/schedules');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Web Development');
        $response->assertJsonPath('data.0.teacher_name', 'Mr. Dara');
        $response->assertJsonCount(2, 'data.0.schedules');
        $response->assertJsonPath('data.0.schedules.0.day_of_week', 1);
        $response->assertJsonPath('data.0.schedules.0.start_time', '18:00:00');
        $response->assertJsonPath('data.0.schedules.1.day_of_week', 3);
    }

    public function test_inactive_classes_are_not_listed(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        $class = SchoolClass::factory()->create(['status' => SchoolClass::STATUS_COMPLETED]);
        ClassSchedule::factory()->forClass($class)->create();

        $response = $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/schedules');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_a_class_without_a_teacher_reports_a_null_teacher_name(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        SchoolClass::factory()->create(['name' => 'Solo Class']);

        $response = $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/schedules');

        $response->assertOk();
        $response->assertJsonPath('data.0.teacher_name', null);
    }

    public function test_another_tenants_classes_are_not_visible(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);
        SchoolClass::factory()->create(['name' => 'Mine']);

        $otherTenant = Tenant::factory()->create();
        $this->createForOtherTenant(fn () => SchoolClass::factory()->forTenant($otherTenant)->create(['name' => 'Theirs']));

        $response = $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/schedules');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Mine');
    }
}
