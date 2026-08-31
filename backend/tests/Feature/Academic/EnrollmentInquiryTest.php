<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\EnrollmentInquiry;
use App\Models\Program;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentInquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_submit_the_register_form(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);
        $program = Program::factory()->create();

        $response = $this->withHeader('X-Tenant', $tenant->slug)->postJson('/api/v1/public/enrollment-inquiries', [
            'name' => 'Sok Dara',
            'phone' => '012345678',
            'email' => 'dara@example.com',
            'program_id' => $program->id,
            'message' => 'I want to join the evening batch.',
        ]);

        $response->assertCreated();

        $inquiry = EnrollmentInquiry::first();
        $this->assertSame('Sok Dara', $inquiry->name);
        $this->assertSame($program->id, $inquiry->program_id);
        $this->assertSame($tenant->id, $inquiry->tenant_id);
    }

    public function test_name_and_phone_are_required(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        $response = $this->withHeader('X-Tenant', $tenant->slug)->postJson('/api/v1/public/enrollment-inquiries', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name', 'phone']);
    }

    public function test_a_program_id_from_another_tenant_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        $otherTenant = Tenant::factory()->create();
        $foreignProgram = app(TenantContext::class)->withoutTenancy(
            fn () => Program::factory()->forTenant($otherTenant)->create()
        );

        $response = $this->withHeader('X-Tenant', $tenant->slug)->postJson('/api/v1/public/enrollment-inquiries', [
            'name' => 'Sok Dara',
            'phone' => '012345678',
            'program_id' => $foreignProgram->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('program_id');
    }
}
