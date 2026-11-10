<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\DiscountCode;
use App\Models\DiscountCodeRedemption;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Invoices > Discount codes: the list, the form, and Assign discount to student. */
class DiscountCodeTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [
        Permissions::DISCOUNT_CODES_VIEW, Permissions::DISCOUNT_CODES_CREATE,
        Permissions::DISCOUNT_CODES_UPDATE, Permissions::DISCOUNT_CODES_DELETE,
    ];

    public function test_a_code_is_added_in_capitals_and_may_have_no_end_date(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $this->postJson('/api/v1/discount-codes', [
            'code' => ' newyear10 ',
            'name' => 'New Year',
            'discount_type' => DiscountCode::TYPE_PERCENT,
            'value' => 10,
            'max_discount' => 20,
            'starts_on' => now()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.code', 'NEWYEAR10')
            ->assertJsonPath('data.audience', DiscountCode::AUDIENCE_PUBLIC)
            ->assertJsonPath('data.expires_on', null)
            ->assertJsonPath('data.effective_status', 'active')
            ->assertJsonPath('data.uses_count', 0);

        // Codes are unique.
        $this->postJson('/api/v1/discount-codes', [
            'code' => 'NEWYEAR10', 'name' => 'Again', 'discount_type' => 'amount', 'value' => 5, 'starts_on' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_rules_on_value_dates_and_spaces(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $base = ['name' => 'X', 'discount_type' => 'percent', 'starts_on' => '2026-11-10'];

        $this->postJson('/api/v1/discount-codes', [...$base, 'code' => 'BAD CODE', 'value' => 101, 'expires_on' => '2026-11-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'value', 'expires_on']);
        $this->postJson('/api/v1/discount-codes', [...$base, 'code' => str_repeat('A', 31), 'value' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_assign_makes_each_student_their_own_code(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $a = Student::factory()->create(['student_code' => 'NTS000543']);
        $b = Student::factory()->create(['student_code' => 'NTS000544']);
        $main = DiscountCode::factory()->create(['code' => 'D01', 'value' => 15, 'expires_on' => null]);
        $day = now('Asia/Phnom_Penh')->format('dmy');

        $this->postJson("/api/v1/discount-codes/{$main->id}/students", ['student_ids' => [$a->id]])
            ->assertOk()
            ->assertJsonPath('meta.assigned', 1)
            ->assertJsonPath('meta.codes.0', "NTS000543_D01_{$day}")
            ->assertJsonPath('data.students_count', 1)
            ->assertJsonPath('data.students.0.code', "NTS000543_D01_{$day}");

        $own = DiscountCode::query()->where('code', "NTS000543_D01_{$day}")->sole();
        $this->assertSame($main->id, $own->parent_id);
        $this->assertSame(DiscountCode::AUDIENCE_PERSONAL, $own->audience);
        $this->assertEquals(15, $own->value);
        $this->assertNull($own->expires_on);
        $this->assertSame([$a->id], $own->students()->pluck('students.id')->all());

        // Again: A keeps their code (nothing new), B gets one.
        $this->postJson("/api/v1/discount-codes/{$main->id}/students", ['student_ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('meta.assigned', 1)
            ->assertJsonPath('data.students_count', 2);

        // The list shows only main codes.
        $this->getJson('/api/v1/discount-codes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'D01');

        // A student code cannot be assigned on.
        $this->postJson("/api/v1/discount-codes/{$own->id}/students", ['student_ids' => [$b->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('discount_code_id');

        $this->actingAsAdminWithPermissions([Permissions::DISCOUNT_CODES_VIEW]);
        $this->postJson("/api/v1/discount-codes/{$main->id}/students", ['student_ids' => [$a->id]])->assertForbidden();
    }

    public function test_editing_the_main_code_changes_the_students_codes_too(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $student = Student::factory()->create(['student_code' => 'NTS1']);
        $main = DiscountCode::factory()->create(['code' => 'D02']);
        $this->postJson("/api/v1/discount-codes/{$main->id}/students", ['student_ids' => [$student->id]])->assertOk();

        $this->putJson("/api/v1/discount-codes/{$main->id}", [
            'code' => 'D02', 'name' => 'Bigger', 'discount_type' => 'amount', 'value' => 7,
            'starts_on' => now()->subDay()->toDateString(), 'expires_on' => null, 'status' => 'inactive',
        ])->assertOk();

        $own = $main->children()->sole();
        $this->assertSame('Bigger', $own->name);
        $this->assertSame('amount', $own->discount_type);
        $this->assertEquals(7, $own->value);
        $this->assertNull($own->expires_on);
        $this->assertSame('inactive', $own->status);
    }

    public function test_taking_the_discount_back_from_a_student(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        [$a, $b] = Student::factory()->count(2)->create();
        $main = DiscountCode::factory()->create(['code' => 'D03']);
        $this->postJson("/api/v1/discount-codes/{$main->id}/students", ['student_ids' => [$a->id, $b->id]])->assertOk();

        // Not used: the code goes.
        $this->deleteJson("/api/v1/discount-codes/{$main->id}/students/{$a->id}")->assertOk()->assertJsonPath('data.students_count', 1);
        $this->assertSame(1, $main->children()->count());

        // Used: kept for the history, but no longer theirs.
        $usedCode = $main->children()->sole();
        DiscountCodeRedemption::query()->create(['discount_code_id' => $usedCode->id, 'student_id' => $b->id, 'amount' => 5, 'currency' => 'USD']);
        $this->deleteJson("/api/v1/discount-codes/{$main->id}/students/{$b->id}")->assertOk()->assertJsonPath('data.students_count', 0);
        $this->assertSame('inactive', $usedCode->refresh()->status);
        $this->assertSame(0, $usedCode->students()->count());

        // A used student code also keeps the main code from being deleted.
        $this->deleteJson("/api/v1/discount-codes/{$main->id}")->assertUnprocessable();
    }

    public function test_a_used_code_cannot_be_deleted_and_an_unused_one_can(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $used = DiscountCode::factory()->create();
        DiscountCodeRedemption::query()->create([
            'discount_code_id' => $used->id, 'student_id' => Student::factory()->create()->id, 'amount' => 5, 'currency' => 'USD',
        ]);
        $unused = DiscountCode::factory()->create();
        $this->postJson("/api/v1/discount-codes/{$unused->id}/students", ['student_ids' => [Student::factory()->create()->id]])->assertOk();

        $this->getJson('/api/v1/discount-codes')->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson("/api/v1/discount-codes/{$used->id}")->assertUnprocessable();
        // Unused: goes with its students' codes.
        $this->deleteJson("/api/v1/discount-codes/{$unused->id}")->assertNoContent();
        $this->assertSame(0, DiscountCode::query()->where('parent_id', $unused->id)->count());
    }

    public function test_each_action_needs_its_own_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::DISCOUNT_CODES_VIEW]);
        $code = DiscountCode::factory()->create();
        $student = Student::factory()->create();

        $this->getJson('/api/v1/discount-codes')->assertOk();
        $this->getJson('/api/v1/discount-codes/options?search=a')->assertOk();
        $this->postJson('/api/v1/discount-codes', [])->assertForbidden();
        $this->putJson("/api/v1/discount-codes/{$code->id}", [])->assertForbidden();
        $this->deleteJson("/api/v1/discount-codes/{$code->id}")->assertForbidden();
        $this->deleteJson("/api/v1/discount-codes/{$code->id}/students/{$student->id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/v1/discount-codes')->assertForbidden();
    }
}
