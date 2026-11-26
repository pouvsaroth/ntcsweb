<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\PartyBranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned. Political Party > Branches: a branch / office placed at a
 * province, district, commune or village (`level`), with every level above
 * it filled in. The place ids point at the central geography tables (no
 * relation across the two databases). `code` (BR00001) is given from the id.
 */
#[Fillable(['name', 'level', 'province_id', 'district_id', 'commune_id', 'village_id', 'leader_name', 'leader_phone', 'address', 'opened_on', 'is_active', 'remark'])]
class PartyBranch extends Model
{
    /** @use HasFactory<PartyBranchFactory> */
    use Auditable, HasFactory;

    public const LEVELS = ['province', 'district', 'commune', 'village'];

    protected $connection = 'tenant';

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'opened_on' => 'date',
            'province_id' => 'integer',
            'district_id' => 'integer',
            'commune_id' => 'integer',
            'village_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (PartyBranch $branch) {
            if ($branch->code === null) {
                $branch->forceFill(['code' => sprintf('BR%05d', $branch->id)])->saveQuietly();
            }
        });
    }

    public function auditModule(): string
    {
        return 'Political Party';
    }
}
