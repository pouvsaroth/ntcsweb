<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned, one row (see current()). How HRM > Performance Management's
 * final score is weighted — KPIs + goals + the manager's assessment, adding
 * up to 100. The self-assessment is shown next to it but doesn't count.
 */
#[Fillable(['kpi_weight', 'goal_weight', 'manager_weight'])]
class PerformanceSetting extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'kpi_weight' => 'integer',
            'goal_weight' => 'integer',
            'manager_weight' => 'integer',
        ];
    }

    /** The school's settings — the migration inserts the row; this recreates it with the defaults if it was ever removed. */
    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? tap(new self, fn (self $settings) => $settings->save())->refresh();
    }

    public function auditModule(): string
    {
        return 'Performance';
    }
}
