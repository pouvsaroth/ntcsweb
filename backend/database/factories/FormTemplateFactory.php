<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormCategory;
use App\Models\FormTemplate;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormTemplate>
 */
class FormTemplateFactory extends Factory
{
    protected $model = FormTemplate::class;

    public function definition(): array
    {
        return [
            'form_category_id' => FormCategory::factory(),
            'code' => 'TT-'.strtoupper(fake()->unique()->lexify('???')).'-FM-001',
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'order' => 0,
            'is_active' => true,
        ];
    }

    /**
     * See CurrencyRateFactory::forTenant()'s docblock for why this exists.
     * Pass an explicit `form_category_id` alongside this when the default
     * nested FormCategory::factory() would otherwise be created without a
     * tenant to attach to (e.g. inside TenantContext::withoutTenancy()).
     */
    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (FormTemplate $template) use ($tenant) {
            $template->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
