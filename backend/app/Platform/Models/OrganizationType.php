<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What kind of customer a tenant is (School, Company, Organization, Ministry…)
 * and its word for "school" in each language. A tenant with no type is a School.
 *
 * @property int $id
 * @property string $code
 * @property array<string, string> $words
 * @property int $sort_order
 * @property string $status
 */
class OrganizationType extends Model
{
    public const SCHOOL = 'school';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** The languages each type keeps a word for; en_plural is "Schools" in English. */
    public const WORD_KEYS = ['en', 'en_plural', 'km', 'zh', 'ja', 'ko'];

    protected $table = 'bo_organization_types';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'words' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'organization_type_id');
    }

    /** The words the admin screens use for this tenant (a School when it has no type). */
    public static function wordsFor(?Tenant $tenant): array
    {
        $type = $tenant?->organization_type_id ? static::query()->find($tenant->organization_type_id) : null;

        return [
            'code' => $type?->code ?? self::SCHOOL,
            'words' => $type?->words ?? ['en' => 'School', 'en_plural' => 'Schools', 'km' => 'សាលា', 'zh' => '学校', 'ja' => '学校', 'ko' => '학교'],
        ];
    }
}
