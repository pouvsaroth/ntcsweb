<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website sign-up "Pay later" (owner, 2026-11-20): the account is made at once
 * on the chosen plan, unpaid. Its subscription is `awaiting_payment` for 7
 * days, then suspended with no grace days until a payment is recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bo_signups', function (Blueprint $table) {
            $table->timestamp('pay_later_at')->nullable();
        });
        Schema::table('bo_school_subscriptions', function (Blueprint $table) {
            $table->boolean('awaiting_payment')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('bo_school_subscriptions', fn (Blueprint $table) => $table->dropColumn('awaiting_payment'));
        Schema::table('bo_signups', fn (Blueprint $table) => $table->dropColumn('pay_later_at'));
    }
};
