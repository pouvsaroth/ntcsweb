<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. A kind of staff leave (HRM > Leave Management > Leave types)
 * — Annual, Sick, Maternity, ... `gender` limits it to one gender (null =
 * everyone). How many days a year it gives is its LeavePolicy's job.
 */
#[Fillable(['code', 'name', 'color', 'is_paid', 'allow_half_day', 'requires_attachment', 'gender', 'description', 'is_active'])]
class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use Auditable, HasFactory;

    public const GENDERS = ['male', 'female'];

    protected $connection = 'tenant';

    protected $attributes = [
        'is_paid' => true,
        'allow_half_day' => true,
        'requires_attachment' => false,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'allow_half_day' => 'boolean',
            'requires_attachment' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function policies(): HasMany
    {
        return $this->hasMany(LeavePolicy::class);
    }

    public function auditModule(): string
    {
        return 'Leave';
    }
}
