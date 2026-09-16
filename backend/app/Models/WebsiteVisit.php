<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WebsiteVisitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned. One calendar day's visit count — see the migration's
 * docblock and WebsiteVisitStats, which is the only thing that ever reads
 * or writes this model.
 *
 * @property \Illuminate\Support\Carbon $visit_date
 * @property int $visits
 */
#[Fillable(['visit_date', 'visits'])]
class WebsiteVisit extends Model
{
    /** @use HasFactory<WebsiteVisitFactory> */
    use HasFactory;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'visits' => 'integer',
        ];
    }
}
