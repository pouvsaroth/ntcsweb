<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Students to add discount: when an enrollment turns Completed it waits on
 * Invoices > Discount codes > Students to add discount until staff give the
 * student a discount code or skip them. `completed_on` is the day it turned
 * Completed. Only completions from now on are listed — existing completed
 * enrollments keep both columns empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->date('completed_on')->nullable()->after('start_study_on');
            // waiting | given | skipped — null: not followed up (completed before this, or not completed).
            $table->string('discount_follow_up', 10)->nullable()->after('completed_on');
            $table->index('discount_follow_up');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex(['discount_follow_up']);
            $table->dropColumn(['completed_on', 'discount_follow_up']);
        });
    }
};
