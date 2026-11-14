@php
    // Shown in USD and in KHR, the other side converted with the Currency
    // Rates rate in effect on the invoice date.
    $rateRow = \App\Support\Billing\Money::rateOn($invoice->invoice_date);
    $rate = $rateRow !== null ? (float) $rateRow->khr_per_usd : null;
    $both = fn ($amount) => \App\Support\Billing\Money::pairText($amount, $invoice->currency, $rate);
@endphp
@component('mail::message')
# {{ $tenant?->name ?? config('app.name') }}

{{ __('invoice.greeting', ['name' => $invoice->billToName()]) }}

{{ __('invoice.issued_notice') }}

**{{ __('invoice.invoice') }}:** {{ $invoice->invoice_number }}

@component('mail::table')
| | USD | KHR |
|:--|--:|--:|
| **{{ __('invoice.total') }}** | {{ $both($invoice->total)['USD'] }} | {{ $both($invoice->total)['KHR'] }} |
| **{{ __('invoice.paid') }}** | {{ $both($invoice->paid_amount)['USD'] }} | {{ $both($invoice->paid_amount)['KHR'] }} |
| **{{ __('invoice.balance') }}** | {{ $both($invoice->balance)['USD'] }} | {{ $both($invoice->balance)['KHR'] }} |
@endcomponent

@if($rateRow)
{{ __('invoice.exchange_rate') }}: 1 USD = {{ number_format((float) $rateRow->khr_per_usd) }} ៛ ({{ __('invoice.rate_from', ['date' => $rateRow->effective_date->format('d-m-Y')]) }})
@else
{{ __('invoice.no_rate') }}
@endif

{{ __('invoice.attached_notice') }}

{{ __('invoice.thanks') }}<br>
{{ $tenant?->name ?? config('app.name') }}
@endcomponent
