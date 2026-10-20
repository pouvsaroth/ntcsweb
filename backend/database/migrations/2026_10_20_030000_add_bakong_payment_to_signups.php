<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bakong KHQR payment for website sign-ups (docs/backoffice/stage-8-signup.md, 8c).
 */
return new class extends Migration
{
    public function up(): void
    {
        // NewTech's own static KHQR ("receive any amount") and the Bakong Open API token.
        Schema::table('bo_billing_settings', function (Blueprint $table) {
            $table->text('khqr_template')->nullable();
            $table->text('bakong_token')->nullable(); // encrypted
            $table->timestamp('bakong_token_expires_at')->nullable();
        });

        Schema::table('bo_signups', function (Blueprint $table) {
            // The fixed-amount code shown to the visitor, and its MD5 — what Bakong looks the payment up by.
            $table->text('khqr')->nullable();
            $table->string('khqr_md5', 32)->nullable()->index();
            $table->timestamp('last_checked_at')->nullable();
            // What Bakong says was paid.
            $table->string('bakong_hash', 100)->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->string('paid_currency', 3)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('bo_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bo_signups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
            $table->dropColumn(['khqr', 'khqr_md5', 'last_checked_at', 'bakong_hash', 'paid_amount', 'paid_currency']);
        });
        Schema::table('bo_billing_settings', function (Blueprint $table) {
            $table->dropColumn(['khqr_template', 'bakong_token', 'bakong_token_expires_at']);
        });
    }
};
