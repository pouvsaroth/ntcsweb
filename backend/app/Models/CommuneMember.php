<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. Political Party > Commune members: an HRM staff member on a
 * commune's list for an election year, as a full member (ពេញសិទ្ធិ) or a
 * reserve member (បម្រុង), at a position (No. 1, 2, …) in that list.
 */
#[Fillable(['election_year', 'province_id', 'district_id', 'commune_id', 'staff_id', 'role', 'position', 'remark'])]
class CommuneMember extends Model
{
    use Auditable;

    public const FULL = 'full';

    public const RESERVE = 'reserve';

    public const ROLES = [self::FULL, self::RESERVE];

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'election_year' => 'integer',
            'province_id' => 'integer',
            'district_id' => 'integer',
            'commune_id' => 'integer',
            'staff_id' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    public function auditModule(): string
    {
        return 'Political Party';
    }
}
