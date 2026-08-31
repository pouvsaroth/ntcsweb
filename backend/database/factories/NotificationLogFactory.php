<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Support\Billing\NotificationChannelName;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    protected $model = NotificationLog::class;

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'channel' => NotificationChannelName::EMAIL,
            'recipient' => fake()->safeEmail(),
            'type' => 'invoice_issued',
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (NotificationLog $log) use ($tenant) {
            $log->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
