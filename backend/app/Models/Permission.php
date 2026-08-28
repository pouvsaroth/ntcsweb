<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Platform-wide table.
 *
 * A permission is a capability of the codebase ("students.create"), not user
 * data, so the catalog is identical for every school and is owned by the
 * seeder rather than by any admin screen. Schools compose these into their own
 * roles instead.
 *
 * @property string $slug
 * @property string $group
 */
#[Fillable(['slug', 'name', 'group', 'description'])]
class Permission extends Model
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
