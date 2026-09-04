<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LeaveRequest;
use App\Models\LeaveRequestAttachment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequestAttachment>
 */
class LeaveRequestAttachmentFactory extends Factory
{
    protected $model = LeaveRequestAttachment::class;

    public function definition(): array
    {
        return [
            'leave_request_id' => LeaveRequest::factory(),
            'file_path' => 'tenants/0/leave-request-attachments/'.fake()->uuid().'.jpg',
            'file_name' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (LeaveRequestAttachment $attachment) use ($tenant) {
            $attachment->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
