<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A system the platform sells (docs/backoffice/stage-9-products.md): a name and
 * logo + the modules it is built from. Plans belong to one; a customer holds
 * one subscription per system.
 */
class Product extends Model
{
    /** The School Management System: every plan from before systems existed. */
    public const SCHOOL = 'school';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'bo_products';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    public static function school(): self
    {
        return self::query()->where('code', self::SCHOOL)->firstOrFail();
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    /** "School Management System" or its Khmer name for a Khmer reader. */
    public function label(?string $locale = null): string
    {
        return ($locale === 'km' && $this->name_km) ? $this->name_km : $this->name;
    }
}
