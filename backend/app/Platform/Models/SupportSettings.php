<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/** The platform's one support settings row (id 1): how long a support view lasts. */
class SupportSettings extends Model
{
    protected $table = 'bo_support_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['duration_minutes' => 'integer'];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(['id' => 1], ['duration_minutes' => 30]);
    }
}
