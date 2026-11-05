<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\LenderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tenant-owned. Accounting > Borrowings > Lenders: a company or person money is borrowed from. */
#[Fillable(['name', 'type', 'contact_person', 'phone', 'email', 'address', 'notes', 'is_active'])]
class Lender extends Model
{
    /** @use HasFactory<LenderFactory> */
    use Auditable, HasFactory;

    public const TYPES = ['company', 'person'];

    protected $connection = 'tenant';

    protected $attributes = [
        'type' => 'company',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function borrowings(): HasMany
    {
        return $this->hasMany(Borrowing::class);
    }

    public function auditModule(): string
    {
        return 'Accounting';
    }
}
