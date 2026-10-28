<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\EnrollmentInquiry;
use App\Models\Inquiry;
use App\Models\Program;
use App\Models\Tenant;
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

        // It also shows in Front Office > Inquiry, as a new Website inquiry.
        $lead = Inquiry::query()->sole();
        $this->assertSame('website', $lead->source);
        $this->assertSame('new', $lead->status);
        $this->assertSame('Sok Dara', $lead->name);
        $this->assertSame('012345678', $lead->phone);
        $this->assertSame($program->title, $lead->interested_in);
        $this->assertSame('I want to join the evening batch.', $lead->note);
        $this->assertSame($inquiry->id, $lead->enrollment_inquiry_id);
    }

    public function test_name_and_phone_are_required(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        $response = $this->withHeader('X-Tenant', $tenant->slug)->postJson('/api/v1/public/enrollment-inquiries', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name', 'phone']);
    }
}
