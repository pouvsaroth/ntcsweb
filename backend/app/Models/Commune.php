<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CommuneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-wide, read-only reference data — see Province.
 *
 * @property string $code
 * @property int $district_id
 */
class Commune extends Model
{
    /** @use HasFactory<CommuneFactory> */
    use HasFactory;

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function villages(): HasMany
    {
        return $this->hasMany(Village::class);
    }
}
