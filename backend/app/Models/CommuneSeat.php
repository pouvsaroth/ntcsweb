<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned. Political Party > Election offices: how many commune /
 * sangkat council seats a commune has in one election year.
 */
#[Fillable(['election_year', 'province_id', 'district_id', 'commune_id', 'seats'])]
class CommuneSeat extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $table = 'election_commune_seats';

    protected function casts(): array
    {
        return [
            'election_year' => 'integer',
            'province_id' => 'integer',
            'district_id' => 'integer',
            'commune_id' => 'integer',
            'seats' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'Political Party';
    }
}
