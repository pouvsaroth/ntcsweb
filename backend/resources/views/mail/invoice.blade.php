@component('mail::message')
# {{ $tenant?->name ?? config('app.name') }}

{{ __('invoice.greeting', ['name' => $invoice->billToName()]) }}

{{ __('invoice.issued_notice') }}

**{{ __('invoice.invoice') }}:** {{ $invoice->invoice_number }}
**{{ __('invoice.total') }}:** {{ \App\Support\Billing\Money::format($invoice->total, $invoice->currency) }}
**{{ __('invoice.paid') }}:** {{ \App\Support\Billing\Money::format($invoice->paid_amount, $invoice->currency) }}
**{{ __('invoice.balance') }}:** {{ \App\Support\Billing\Money::format($invoice->balance, $invoice->currency) }}

{{ __('invoice.attached_notice') }}

{{ __('invoice.thanks') }}<br>
{{ $tenant?->name ?? config('app.name') }}
@endcomponent
