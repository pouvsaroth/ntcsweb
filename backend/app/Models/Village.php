<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VillageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform-wide, read-only reference data — see Province.
 *
 * `code` is what `students.village_code` stores (as free text, not a hard
 * FK — see the migration for why).
 *
 * @property string $code
 * @property int $commune_id
 */
class Village extends Model
{
    /** @use HasFactory<VillageFactory> */
    use HasFactory;

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }
}
