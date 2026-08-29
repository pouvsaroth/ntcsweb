<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProvinceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-wide, read-only reference data — see the migration that creates
 * this table for provenance. Identical for every school, owned by
 * CambodiaGeographySeeder, never edited through an admin screen.
 * `HasFactory` exists for tests only (see GeographyControllerTest) — nothing
 * in the app itself ever creates one of these rows.
 *
 * @property string $code
 * @property string $name_km
 * @property string $name_latin
 */
class Province extends Model
{
    /** @use HasFactory<ProvinceFactory> */
    use HasFactory;

    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }
}
