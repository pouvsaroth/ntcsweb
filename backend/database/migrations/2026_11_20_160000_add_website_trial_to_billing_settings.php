<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Back office > Billing > Settings: "Free trial on the website" (owner,
 * 2026-11-20). Off: no Free trial card on the plans pages and no free-trial
 * sign-up; visitors use Try the demo or buy a plan. Turned off now at the
 * owner's request; a fresh install keeps it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bo_billing_settings', function (Blueprint $table) {
            $table->boolean('website_trial')->default(true)->after('trial_plan_id');
        });
        // The owner's switch-off is for the real install; tests keep the default (on).
        if (! app()->runningUnitTests()) {
            DB::table('bo_billing_settings')->update(['website_trial' => false]);
        }
    }

    public function down(): void
    {
        Schema::table('bo_billing_settings', function (Blueprint $table) {
            $table->dropColumn('website_trial');
        });
    }
};
