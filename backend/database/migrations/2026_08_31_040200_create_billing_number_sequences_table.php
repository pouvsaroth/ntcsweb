<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs BillingNumberGenerator — the same concurrency-safe counter pattern
 * as `student_id_sequences` (SELECT ... FOR UPDATE inside a transaction),
 * generalized for more than one numbered document: `series` separates an
 * invoice's counter from a payment/receipt's, and `year` makes each restart
 * at 1 every year (INV-2026-000001, INV-2027-000001, ...) without the two
 * years' counters ever colliding.
 *
 * One shared table for both series rather than two near-identical ones —
 * the row shape is identical either way, only the key differs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('series', 20);
            $table->string('prefix', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'series', 'prefix', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_number_sequences');
    }
};
