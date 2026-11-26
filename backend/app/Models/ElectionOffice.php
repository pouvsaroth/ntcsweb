<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ElectionOfficeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned. Political Party > Election offices: a place where people
 * vote in one election year, in a commune (optionally a village). Place
 * ids point at the central geography tables (see App\Support\Geography\Places).
 */
#[Fillable(['election_year', 'code', 'name', 'province_id', 'district_id', 'commune_id', 'village_id', 'location', 'voters'])]
class ElectionOffice extends Model
{
    /** @use HasFactory<ElectionOfficeFactory> */
    use Auditable, HasFactory;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'election_year' => 'integer',
            'province_id' => 'integer',
            'district_id' => 'integer',
            'commune_id' => 'integer',
            'village_id' => 'integer',
            'voters' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'Political Party';
    }
}
