<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;

final class ReceiptPdfService
{
    public function render(Payment $payment): string
    {
        $payment->loadMissing(['invoice.tenant', 'invoice.student']);

        return Pdf::loadView('pdf.receipt', ['payment' => $payment, 'invoice' => $payment->invoice, 'tenant' => $payment->invoice->tenant])
            ->setPaper('a4')
            ->output();
    }

    public function filename(Payment $payment): string
    {
        return $payment->payment_number.'.pdf';
    }
}
