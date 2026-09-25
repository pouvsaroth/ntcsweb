<?php

declare(strict_types=1);

use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Accounting\FinancialTransactionService;
use App\Support\Billing\PaymentStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * One-time data fix: the "Legacy payment migration" payments were imported
 * straight into `payments` from the old system, bypassing
 * PaymentService::record() — so no INCOME posting was ever made for them,
 * and every ledger-based figure (the dashboard's Monthly/Daily Income, the
 * income popup, Profit & Loss, Cash Flow) left them out entirely.
 *
 * Posts each one exactly as a normal payment would, through
 * FinancialTransactionService::recognizeIncomeForPayment() (same revenue
 * account resolution, same currency, dated on the payment's own date), so
 * the result is indistinguishable from having been recorded live. Only
 * COMPLETED payments with no ledger row at all are touched; the service's
 * own reference check makes a re-run a no-op. A payment dated inside a
 * closed accounting period is skipped (and logged) rather than forced in.
 *
 * Tenant migrations run without a TenantContext (see
 * TenantProvisioningService::provision()), so this resolves its own tenant
 * from the database it's running against. No match (e.g. the test
 * database, whose tenants are created after migrating) means nothing to do.
 */
return new class extends Migration
{
    public function up(): void
    {
        $databaseName = DB::connection('tenant')->getDatabaseName();
        // `migrate --database=tenant` makes the tenant DB the default
        // connection, so the tenants table has to be read from central explicitly.
        $tenant = Tenant::on(config('tenancy.database.central_connection'))->get()->first(fn (Tenant $tenant) => $tenant->database()->getName() === $databaseName);

        if ($tenant === null) {
            return;
        }

        $service = app(FinancialTransactionService::class);

        // AuditLogger writes via DB::table('audit_logs') on the default
        // connection — central, outside a migration. Left on `tenant`, that
        // insert fails and (Postgres) aborts this migration's transaction.
        $previousDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(config('tenancy.database.central_connection'));

        try {
            $this->backfill($service, $tenant);
        } finally {
            DB::setDefaultConnection($previousDefault);
        }
    }

    private function backfill(FinancialTransactionService $service, Tenant $tenant): void
    {
        app(TenantContext::class)->runFor($tenant, function () use ($service, $tenant) {
            $skipped = 0;

            Payment::query()
                ->where('status', PaymentStatus::COMPLETED)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from((new FinancialTransaction)->getTable())
                    ->where('reference_type', Payment::class)
                    ->whereColumn('reference_id', 'payments.id'))
                ->orderBy('id')
                ->chunkById(200, function ($payments) use ($service, &$skipped) {
                    foreach ($payments as $payment) {
                        try {
                            $service->recognizeIncomeForPayment($payment, null);
                        } catch (ValidationException) {
                            $skipped++;
                        }
                    }
                });

            if ($skipped > 0) {
                Log::warning("Legacy income backfill for tenant {$tenant->slug}: skipped {$skipped} payment(s) dated in a closed accounting period.");
            }
        });
    }

    public function down(): void
    {
        // Irreversible on purpose — these postings reflect real income.
    }
};
