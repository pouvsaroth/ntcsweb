<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\LoanCustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned. Loan > Customers: someone who keeps savings with the owner
 * or borrows from them. `code` (C00001) is given from the id on create.
 */
#[Fillable(['name', 'gender', 'phone', 'id_card_no', 'date_of_birth', 'address', 'notes', 'is_active'])]
class LoanCustomer extends Model
{
    /** @use HasFactory<LoanCustomerFactory> */
    use Auditable, HasFactory;

    public const GENDERS = ['male', 'female'];

    protected $connection = 'tenant';

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'date_of_birth' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (LoanCustomer $customer) {
            if ($customer->code === null) {
                $customer->forceFill(['code' => sprintf('C%05d', $customer->id)])->saveQuietly();
            }
        });
    }

    public function auditModule(): string
    {
        return 'Loan';
    }
}
