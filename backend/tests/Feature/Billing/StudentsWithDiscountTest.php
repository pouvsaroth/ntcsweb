<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\DiscountCode;
use App\Models\DiscountCodeRedemption;
use App\Models\Enrollment;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Invoices > Students with discount: students holding a code usable today. */
class StudentsWithDiscountTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    /** A student's own code given from $main (as Assign does). */
    private function give(DiscountCode $main, Student $student, array $overrides = []): DiscountCode
    {
        $code = DiscountCode::factory()->create([
            'parent_id' => $main->id,
            'audience' => DiscountCode::AUDIENCE_PERSONAL,
            'code' => $student->student_code.'_'.$main->code,
            'discount_type' => $main->discount_type,
            'value' => $main->value,
            ...$overrides,
        ]);
        $code->students()->attach($student->id);

        return $code;
    }

    public function test_only_students_with_a_code_usable_today_are_listed(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::DISCOUNT_CODES_VIEW]);
        $main = DiscountCode::factory()->create(['code' => 'NEWYEAR', 'name' => 'New year', 'value' => 15]);

        $dara = Student::factory()->create(['student_code' => 'NTS001', 'first_name' => 'Dara', 'last_name' => 'Sok', 'phone' => '012000111']);
        Enrollment::factory()->forStudent($dara)->create();
        $active = $this->give($main, $dara, ['expires_on' => null]);
        DiscountCodeRedemption::query()->create(['discount_code_id' => $active->id, 'student_id' => $dara->id, 'amount' => 15, 'currency' => 'USD']);
        DiscountCodeRedemption::query()->create(['discount_code_id' => $active->id, 'student_id' => $dara->id, 'amount' => 15, 'currency' => 'USD', 'status' => DiscountCodeRedemption::STATUS_RELEASED]);

        // Not listed: expired, not started yet, turned off.
        $this->give($main, Student::factory()->create(['student_code' => 'NTS002']), ['expires_on' => now()->subDay()->toDateString()]);
        $this->give($main, Student::factory()->create(['student_code' => 'NTS003']), ['starts_on' => now()->addDay()->toDateString()]);
        $this->give($main, Student::factory()->create(['student_code' => 'NTS004']), ['status' => DiscountCode::STATUS_INACTIVE]);

        $response = $this->getJson('/api/v1/discount-codes/students')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.students', 1)
            ->assertJsonPath('data.0.student.student_code', 'NTS001')
            ->assertJsonPath('data.0.student.name', 'Dara Sok')
            ->assertJsonPath('data.0.student.phone', '012000111')
            ->assertJsonPath('data.0.code.code', 'NTS001_NEWYEAR')
            ->assertJsonPath('data.0.code.main_code', 'NEWYEAR')
            ->assertJsonPath('data.0.code.name', 'New year')
            ->assertJsonPath('data.0.code.value', 15)
            ->assertJsonPath('data.0.code.expires_on', null)
            // Only applied uses count.
            ->assertJsonPath('data.0.uses', 1);
        $this->assertCount(1, $response->json('data.0.courses'));
    }

    public function test_it_needs_discount_codes_view(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/discount-codes/students')->assertForbidden();
    }
}
