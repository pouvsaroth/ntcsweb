<?php

declare(strict_types=1);

namespace App\Platform\Jobs;

use App\Models\Tenant;
use App\Platform\Services\SchoolProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sets up one school in the background. One try: a failure leaves the school
 * `failed` with the reason on its page, and staff press Retry — a person
 * decides, rather than the queue quietly hammering a broken step.
 */
class ProvisionSchool implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $tenantId) {}

    /** Never two runs for the same school at once. */
    public function uniqueId(): string
    {
        return (string) $this->tenantId;
    }

    public function handle(SchoolProvisioner $provisioner): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null || $tenant->status !== Tenant::STATUS_PROVISIONING) {
            return;
        }

        try {
            $provisioner->run($tenant);
        } catch (\Throwable $e) {
            // Already recorded on the school (failed + reason + log row).
            report($e);
        }
    }
}
