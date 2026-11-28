<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tenant-owned. Political Party > Documents: a folder, inside another or at the top (parent_id null). */
#[Fillable(['parent_id', 'name', 'created_by'])]
class PartyDocumentFolder extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PartyDocument::class, 'folder_id');
    }

    /**
     * This folder and every folder above it, top first.
     *
     * @return list<self>
     */
    public function path(): array
    {
        $path = [];
        $seen = [];
        for ($folder = $this; $folder !== null && ! isset($seen[$folder->id]); $folder = $folder->parent) {
            $seen[$folder->id] = true;
            array_unshift($path, $folder);
        }

        return $path;
    }

    /**
     * This folder's id and every folder id below it.
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $level = [$this->id];
        while ($level !== []) {
            $level = self::query()->whereIn('parent_id', $level)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $level = array_values(array_diff($level, $ids));
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    public function auditModule(): string
    {
        return 'Political Party';
    }
}
