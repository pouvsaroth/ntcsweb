<?php

use App\Platform\Billing\Modules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 5a (docs/backoffice/stage-5-billing.md): plans, subscriptions and the
 * billing settings.
 *
 * Nothing a school has today is taken away: every existing school is put on
 * the Legacy plan — every module, no student limit, no end date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('USD'); // USD | KHR
            // Months one payment covers; null = paid once, no end.
            $table->unsignedSmallInteger('period_months')->nullable();
            // Active students allowed; null = no limit (5d).
            $table->unsignedInteger('max_students')->nullable();
            $table->json('modules'); // module codes (App\Platform\Billing\Modules)
            $table->string('status', 20)->default('active'); // active | inactive
            $table->timestamps();
        });

        // History is kept: a school collects rows as it renews or changes plan.
        // RESTRICT on the plan, so a plan with history cannot be deleted.
        Schema::create('bo_school_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('bo_plans')->restrictOnDelete();
            // trial | active (live) · expired | cancelled | replaced (history)
            $table->string('status', 20);
            $table->date('start_on');
            $table->date('end_on')->nullable(); // last day covered; null = no end
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->timestamp('ended_at')->nullable(); // when it stopped being live
            $table->timestamps();

            $table->index(['tenant_id', 'id']);
        });

        // At most one live subscription per school — a database rule, not just code.
        DB::statement("CREATE UNIQUE INDEX bo_school_subscriptions_one_live ON bo_school_subscriptions (tenant_id) WHERE status IN ('trial', 'active')");

        // The platform's one billing settings row (id = 1).
        Schema::create('bo_billing_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('trial_days')->default(15);
            $table->unsignedSmallInteger('grace_days')->default(7);
            $table->unsignedSmallInteger('warning_days')->default(14);
            // The plan a new school's trial is on; null = Legacy.
            $table->foreignId('trial_plan_id')->nullable()->constrained('bo_plans')->nullOnDelete();
            // Printed on receipts (5b).
            $table->string('company_name')->nullable();
            $table->string('company_address')->nullable();
            $table->string('company_phone', 50)->nullable();
            $table->string('company_email')->nullable();
            $table->string('company_tax_number', 50)->nullable();
            $table->string('company_logo')->nullable();
            $table->timestamps();
        });

        $now = now();
        $legacyId = DB::table('bo_plans')->insertGetId([
            'code' => 'legacy',
            'name' => 'Legacy',
            'description' => 'Everything, no limit, no end — schools from before billing.',
            'price' => 0,
            'currency' => 'USD',
            'period_months' => null,
            'max_students' => null,
            'modules' => json_encode(Modules::codes()),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('bo_billing_settings')->insert([
            'id' => 1, 'trial_days' => 15, 'grace_days' => 7, 'warning_days' => 14,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Every school that exists now (archived and soft-deleted aside) keeps everything.
        $tenants = DB::table('tenants')->whereNull('deleted_at')->where('status', '!=', 'archived')->get(['id', 'created_at']);
        foreach ($tenants as $tenant) {
            DB::table('bo_school_subscriptions')->insert([
                'tenant_id' => $tenant->id,
                'plan_id' => $legacyId,
                'status' => 'active',
                'start_on' => substr((string) ($tenant->created_at ?? $now), 0, 10),
                'end_on' => null,
                'notes' => 'Put on Legacy when billing arrived.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_billing_settings');
        Schema::dropIfExists('bo_school_subscriptions');
        Schema::dropIfExists('bo_plans');
    }
};
