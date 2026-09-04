@component('mail::message')
# {{ $tenant?->name ?? config('app.name') }}

{{ __('invoice.greeting', ['name' => $invoice->student->fullName()]) }}

{{ __('invoice.issued_notice') }}

**{{ __('invoice.invoice') }}:** {{ $invoice->invoice_number }}
**{{ __('invoice.total') }}:** ${{ number_format((float) $invoice->total, 2) }}
**{{ __('invoice.paid') }}:** ${{ number_format((float) $invoice->paid_amount, 2) }}
**{{ __('invoice.balance') }}:** ${{ number_format((float) $invoice->balance, 2) }}

{{ __('invoice.attached_notice') }}

{{ __('invoice.thanks') }}<br>
{{ $tenant?->name ?? config('app.name') }}
@endcomponent
