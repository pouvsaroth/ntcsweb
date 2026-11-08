<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\CoursePackage;
use App\Models\DiscountCode;
use App\Models\DiscountCodeRedemption;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Invoices > Discount codes: the list and form (step 1). */
class DiscountCodeTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [
        Permissions::DISCOUNT_CODES_VIEW, Permissions::DISCOUNT_CODES_CREATE,
        Permissions::DISCOUNT_CODES_UPDATE, Permissions::DISCOUNT_CODES_DELETE,
    ];

    public function test_a_public_percent_code_for_chosen_courses_is_added_in_capitals(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $course = CoursePackage::factory()->create();

        $response = $this->postJson('/api/v1/discount-codes', [
            'code' => ' newyear10 ',
            'name' => 'New Year',
            'audience' => DiscountCode::AUDIENCE_PUBLIC,
            'discount_type' => DiscountCode::TYPE_PERCENT,
            'value' => 10,
            'max_discount' => 20,
            'starts_on' => now()->toDateString(),
            'expires_on' => now()->addMonth()->toDateString(),
            'max_uses' => 50,
            'course_package_ids' => [$course->id],
        ])->assertCreated();

        $response->assertJsonPath('data.code', 'NEWYEAR10')
            ->assertJsonPath('data.effective_status', 'active')
            ->assertJsonPath('data.uses_count', 0)
            ->assertJsonPath('data.course_packages.0.id', $course->id);

        // Codes are unique.
        $this->postJson('/api/v1/discount-codes', [
            'code' => 'NEWYEAR10', 'name' => 'Again', 'audience' => 'public', 'discount_type' => 'amount', 'value' => 5,
            'starts_on' => now()->toDateString(), 'expires_on' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_rules_on_value_dates_spaces_and_personal_students(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $base = ['name' => 'X', 'audience' => 'public', 'discount_type' => 'percent', 'starts_on' => '2026-11-10', 'expires_on' => '2026-11-30'];

        $this->postJson('/api/v1/discount-codes', [...$base, 'code' => 'BAD CODE', 'value' => 101, 'expires_on' => '2026-11-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'value', 'expires_on']);

        $this->postJson('/api/v1/discount-codes', [...$base, 'code' => 'VIP', 'value' => 10, 'audience' => 'personal'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_ids');

        $student = Student::factory()->create();
        $this->postJson('/api/v1/discount-codes', [...$base, 'code' => 'VIP', 'value' => 10, 'audience' => 'personal', 'student_ids' => [$student->id]])
            ->assertCreated()
            ->assertJsonPath('data.students.0.id', $student->id)
            ->assertJsonPath('data.effective_status', fn ($s) => in_array($s, ['active', 'scheduled', 'expired'], true));
    }

    public function test_a_used_code_cannot_be_deleted_and_an_unused_one_can(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $used = DiscountCode::factory()->create();
        DiscountCodeRedemption::query()->create([
            'discount_code_id' => $used->id, 'student_id' => Student::factory()->create()->id, 'amount' => 5, 'currency' => 'USD',
        ]);
        $unused = DiscountCode::factory()->create();

        $this->getJson('/api/v1/discount-codes')->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson("/api/v1/discount-codes/{$used->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/discount-codes/{$unused->id}")->assertNoContent();
    }

    public function test_each_action_needs_its_own_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::DISCOUNT_CODES_VIEW]);
        $code = DiscountCode::factory()->create();

        $this->getJson('/api/v1/discount-codes')->assertOk();
        $this->getJson('/api/v1/discount-codes/options?search=a')->assertOk();
        $this->postJson('/api/v1/discount-codes', [])->assertForbidden();
        $this->putJson("/api/v1/discount-codes/{$code->id}", [])->assertForbidden();
        $this->deleteJson("/api/v1/discount-codes/{$code->id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/v1/discount-codes')->assertForbidden();
    }

    public function test_many_students_each_get_their_own_code_and_percent(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $a = Student::factory()->create(['student_code' => 'S-00001']);
        $b = Student::factory()->create(['student_code' => 'S-00002']);
        DiscountCode::factory()->create(['code' => 'VIP-S00002']); // taken: B gets -2

        $base = ['code_prefix' => 'vip', 'name' => 'Scholarship', 'discount_type' => 'percent',
            'starts_on' => now()->toDateString(), 'expires_on' => now()->addMonth()->toDateString()];

        $this->postJson('/api/v1/discount-codes/bulk', [...$base, 'rows' => [
            ['student_id' => $a->id, 'value' => 20],
            ['student_id' => $b->id, 'value' => 10],
        ]])->assertCreated()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'VIP-S00001')
            ->assertJsonPath('data.1.code', 'VIP-S00002-2');

        $forA = DiscountCode::query()->where('code', 'VIP-S00001')->sole();
        $this->assertSame(DiscountCode::AUDIENCE_PERSONAL, $forA->audience);
        $this->assertEquals(20, $forA->value);
        $this->assertSame([$a->id], $forA->students()->pluck('students.id')->all());
        $forB = DiscountCode::query()->where('code', 'VIP-S00002-2')->sole();
        $this->assertEquals(10, $forB->value);
        $this->assertSame([$b->id], $forB->students()->pluck('students.id')->all());

        // Rules: a percent over 100, the same student twice, no students.
        $this->postJson('/api/v1/discount-codes/bulk', [...$base, 'rows' => [['student_id' => $a->id, 'value' => 120]]])
            ->assertUnprocessable()->assertJsonValidationErrors('rows.0.value');
        $this->postJson('/api/v1/discount-codes/bulk', [...$base, 'rows' => [['student_id' => $a->id, 'value' => 5], ['student_id' => $a->id, 'value' => 5]]])
            ->assertUnprocessable()->assertJsonValidationErrors('rows.0.student_id');
        $this->postJson('/api/v1/discount-codes/bulk', [...$base, 'rows' => []])->assertUnprocessable()->assertJsonValidationErrors('rows');

        $this->actingAsAdminWithPermissions([Permissions::DISCOUNT_CODES_VIEW]);
        $this->postJson('/api/v1/discount-codes/bulk', [...$base, 'rows' => [['student_id' => $a->id, 'value' => 5]]])->assertForbidden();
    }
}
