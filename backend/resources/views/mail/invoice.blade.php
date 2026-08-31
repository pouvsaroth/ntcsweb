@component('mail::message')
# {{ $tenant?->name ?? config('app.name') }}

Dear {{ $invoice->student->fullName() }},

Your invoice has been issued.

**Invoice:** {{ $invoice->invoice_number }}
**Total:** ${{ number_format((float) $invoice->total, 2) }}
**Paid:** ${{ number_format((float) $invoice->paid_amount, 2) }}
**Balance:** ${{ number_format((float) $invoice->balance, 2) }}

Please find your invoice attached.

Thanks,<br>
{{ $tenant?->name ?? config('app.name') }}
@endcomponent
