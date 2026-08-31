<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\InvoiceNotificationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs InvoiceNotificationService on the existing queue worker (the same
 * one already processing ProcessStudentImport) — sending a PDF over
 * Telegram/email is exactly the kind of external-network-call work this
 * queue exists for, so the controller's response never waits on it.
 *
 * Re-establishes TenantContext explicitly: a queued job runs in its own
 * process, with no ambient tenant resolved from a request.
 */
final class SendInvoiceNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $invoiceId,
        private readonly string $recipient,
        private readonly string $channel,
        private readonly int $tenantId,
        private readonly ?int $actorId,
        private readonly string $type = 'invoice_issued',
    ) {}

    public function handle(InvoiceNotificationService $notifications, TenantContext $context): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        $context->runFor($tenant, function () use ($notifications) {
            $invoice = Invoice::query()->findOrFail($this->invoiceId);
            $actor = $this->actorId !== null ? User::query()->find($this->actorId) : null;

            $notifications->send($invoice, $this->recipient, $this->channel, $actor, $this->type);
        });
    }
}
