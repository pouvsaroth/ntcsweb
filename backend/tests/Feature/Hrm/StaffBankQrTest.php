<?php

declare(strict_types=1);

namespace Tests\Feature\Hrm;

use App\Models\Staff;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** A staff member's own bank KHQR: only they can set it; others only see it. */
class StaffBankQrTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_a_staff_member_adds_replaces_and_removes_their_own_qr(): void
    {
        Storage::fake('public');
        $this->actingAsAdminWithPermissions([Permissions::STAFF_UPDATE_OWN]);
        $own = Staff::factory()->create();
        $own->forceFill(['user_id' => $this->admin->id])->save();
        $url = "/api/v1/staff/{$own->id}/bank-qr";

        $this->postJson($url, ['bank_qr' => UploadedFile::fake()->image('qr.png', 300, 300)])->assertOk();
        $first = $own->fresh()->bank_qr_path;
        Storage::disk('public')->assertExists($first);
        $this->getJson("/api/v1/staff/{$own->id}")->assertOk()->assertJsonPath('data.bank_qr_url', Storage::disk('public')->url($first));

        $this->postJson($url, ['bank_qr' => UploadedFile::fake()->image('new.png', 300, 300)])->assertOk();
        Storage::disk('public')->assertMissing($first);

        $this->postJson($url, ['bank_qr' => UploadedFile::fake()->create('qr.pdf', 10, 'application/pdf')])->assertUnprocessable();

        $this->deleteJson($url)->assertOk();
        $this->assertNull($own->fresh()->bank_qr_path);
    }

    public function test_hr_and_payroll_cannot_change_someone_elses_qr(): void
    {
        Storage::fake('public');
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW, Permissions::STAFF_UPDATE, Permissions::PAYROLL_SALARY_STRUCTURES_CREATE, Permissions::PAYROLL_SALARY_STRUCTURES_UPDATE, Permissions::PAYROLL_SALARY_STRUCTURES_DELETE, Permissions::PAYROLL_BASIC_SALARY_CREATE, Permissions::PAYROLL_BASIC_SALARY_UPDATE, Permissions::PAYROLL_BASIC_SALARY_DELETE, Permissions::PAYROLL_ALLOWANCES_CREATE, Permissions::PAYROLL_ALLOWANCES_UPDATE, Permissions::PAYROLL_ALLOWANCES_DELETE, Permissions::PAYROLL_BONUSES_CREATE, Permissions::PAYROLL_BONUSES_UPDATE, Permissions::PAYROLL_BONUSES_DELETE, Permissions::PAYROLL_DEDUCTIONS_CREATE, Permissions::PAYROLL_DEDUCTIONS_UPDATE, Permissions::PAYROLL_DEDUCTIONS_DELETE, Permissions::PAYROLL_OVERTIME_UPDATE, Permissions::PAYROLL_ATTENDANCE_DEDUCTION_UPDATE, Permissions::PAYROLL_TAX_UPDATE, Permissions::PAYROLL_SOCIAL_SECURITY_CREATE, Permissions::PAYROLL_SOCIAL_SECURITY_UPDATE, Permissions::PAYROLL_SOCIAL_SECURITY_DELETE, Permissions::PAYROLL_LOANS_CREATE, Permissions::PAYROLL_LOANS_UPDATE, Permissions::PAYROLL_LOANS_DELETE]);
        $other = Staff::factory()->create();

        $this->postJson("/api/v1/staff/{$other->id}/bank-qr", ['bank_qr' => UploadedFile::fake()->image('qr.png')])->assertForbidden();
        $this->deleteJson("/api/v1/staff/{$other->id}/bank-qr")->assertForbidden();
        $this->assertNull($other->fresh()->bank_qr_path);
    }
}
