<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One student on a company class invoice (Billing > Invoices >
 * Invoice by class), with their code and name copied as they were when the
 * invoice was made. Written once by CompanyInvoiceService, never edited.
 */
#[Fillable(['invoice_id', 'student_id', 'student_code', 'student_name', 'position'])]
class InvoiceStudent extends Model
{
    protected $connection = 'tenant';

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
