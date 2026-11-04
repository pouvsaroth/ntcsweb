{{-- A payment receipt is the invoice template in receipt mode, so both look the same.
     See the $receipt block in invoice.blade.php and ReceiptPdfService. --}}
@include('pdf.invoice', ['receiptPayment' => $payment])
