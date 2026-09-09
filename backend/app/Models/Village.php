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

    /**
     * Pins Village to the central connection explicitly — see
     * BelongsToTenant::getConnectionName()'s docblock for why this can't be
     * left implicit: a model reached through a relationship from an
     * already-converted, database-per-tenant model (e.g. Student::village(),
     * now that Student is one) would otherwise silently follow it onto the
     * `tenant` connection. Village isn't using that trait — it's
     * platform-global, not tenant-owned — so it needs this declared
     * directly, same as Role/User/Language. Commune/District/Province don't
     * need their own copy: each inherits this same explicit central
     * connection from the model above it in the chain.
     */
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }
}
