<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Invoice;
use App\Services\Billing\InvoicePdfService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The first (and, for now, only) Mailable in the project — everything else
 * sends through Laravel Notifications' toMail() (see
 * ResetPasswordNotification). A plain Mailable fits better here: an invoice
 * email needs a binary PDF attachment built on demand, which is simpler to
 * express directly than through a Notification's mail message builder.
 *
 * Deliberately NOT ShouldQueue: Mail::send() auto-defers to the queue for a
 * ShouldQueue mailable, which would return before actually delivering it —
 * EmailChannel needs to know synchronously whether the send succeeded, to
 * write an accurate NotificationLog row. Queuing already happens one level
 * up, at SendInvoiceNotificationJob.
 *
 * Uses the project's existing mail configuration (MAIL_MAILER etc.) — no new
 * mail setup.
 */
class InvoiceMail extends Mailable
{
    public function __construct(private readonly Invoice $invoice) {}

    public function envelope(): Envelope
    {
        $tenant = $this->invoice->tenant;

        return new Envelope(
            subject: __('Invoice :number from :school', [
                'number' => $this->invoice->invoice_number,
                'school' => $tenant?->name ?? config('app.name'),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invoice',
            with: ['invoice' => $this->invoice, 'tenant' => $this->invoice->tenant],
        );
    }

    public function attachments(): array
    {
        $pdf = app(InvoicePdfService::class);

        return [
            \Illuminate\Mail\Mailables\Attachment::fromData(
                fn () => $pdf->render($this->invoice),
                $pdf->filename($this->invoice),
            )->withMime('application/pdf'),
        ];
    }
}
