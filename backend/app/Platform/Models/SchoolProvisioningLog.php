<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/** One step of one provisioning attempt. Written by SchoolProvisioner only. */
class SchoolProvisioningLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bo_school_provisioning_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['attempt' => 'integer', 'duration_ms' => 'integer'];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }
}
