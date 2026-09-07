<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApprovalRequest;
use App\Models\FormTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalRequest>
 */
class ApprovalRequestFactory extends Factory
{
    protected $model = ApprovalRequest::class;

    public function definition(): array
    {
        return [
            'form_template_id' => FormTemplate::factory(),
            'requested_by' => User::factory(),
            'subject' => fake()->sentence(4),
            'details' => fake()->optional()->paragraph(),
            'status' => ApprovalRequest::STATUS_PENDING,
        ];
    }

    /**
     * See CurrencyRateFactory::forTenant()'s docblock for why this exists.
     * Pass explicit `form_template_id`/`requested_by` alongside this when
     * the default nested factories would otherwise be created without a
     * tenant to attach to (e.g. inside TenantContext::withoutTenancy()).
     */
    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (ApprovalRequest $request) use ($tenant) {
            $request->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
