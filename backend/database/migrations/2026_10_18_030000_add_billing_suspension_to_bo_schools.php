<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 5e (docs/backoffice/stage-5-billing.md): a school the billing tick
 * suspended is marked, so a payment lifts exactly that suspension and never
 * one staff made by hand; and the subscription it was for is kept, so a
 * school staff let back in is not suspended again for the same unpaid period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bo_schools', function (Blueprint $table) {
            $table->timestamp('billing_suspended_at')->nullable();
            $table->foreignId('billing_suspended_subscription_id')->nullable()->constrained('bo_school_subscriptions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bo_schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_suspended_subscription_id');
            $table->dropColumn('billing_suspended_at');
        });
    }
};
