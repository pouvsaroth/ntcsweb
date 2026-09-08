<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Payment;
use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;

final class ReceiptPdfService
{
    public function __construct(private readonly TenantContext $context) {}

    public function render(Payment $payment): string
    {
        $payment->loadMissing(['invoice.student']);

        // `invoices`/`payments` live in the tenant database now, so neither
        // carries a tenant() relation anymore — see InvoicePdfService for
        // the identical reasoning.
        $tenant = $this->context->getOrFail();

        return Pdf::loadView('pdf.receipt', ['payment' => $payment, 'invoice' => $payment->invoice, 'tenant' => $tenant])
            ->setPaper('a4')
            ->output();
    }

    public function filename(Payment $payment): string
    {
        return $payment->payment_number.'.pdf';
    }
}
