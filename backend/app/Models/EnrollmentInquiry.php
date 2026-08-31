<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EnrollmentInquiryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A "Register" submission from the public website — see the
 * `create_enrollment_inquiries_table` migration for why this is a lead, not
 * a Student record.
 *
 * @property int $tenant_id
 * @property string $name
 * @property string $phone
 */
#[Fillable(['name', 'phone', 'email', 'program_id', 'message'])]
class EnrollmentInquiry extends Model
{
    /** @use HasFactory<EnrollmentInquiryFactory> */
    use BelongsToTenant, HasFactory;

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
