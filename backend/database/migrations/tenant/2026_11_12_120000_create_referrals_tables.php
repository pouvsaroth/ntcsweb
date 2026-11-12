<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Marketing > Referrals: a student (or inquiry) records who
 * referred them; on the friend's first payment the referrer gets their own
 * copy of the reward discount code picked in the settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('referred_by_student_id')->nullable()->constrained('students')->nullOnDelete();
        });
        Schema::table('inquiries', function (Blueprint $table) {
            $table->foreignId('referred_by_student_id')->nullable()->constrained('students')->nullOnDelete();
        });

        Schema::create('referral_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            // A main discount code; each referrer gets their own copy of it.
            $table->foreignId('reward_discount_code_id')->nullable()->constrained('discount_codes')->nullOnDelete();
            $table->unsignedSmallInteger('valid_days')->nullable(); // empty: the main code's own end date
            $table->timestamps();
        });
        DB::connection($this->getConnection())->table('referral_settings')->insert(['enabled' => false, 'valid_days' => 90, 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('referred_student_id')->unique()->constrained('students')->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending | rewarded
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete(); // the payment that earned it
            $table->foreignId('reward_discount_code_id')->nullable()->constrained('discount_codes')->nullOnDelete(); // the referrer's own code
            $table->date('rewarded_on')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('referrer_student_id');
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'referral_settings');
        SchoolTables::secure($db, 'referrals');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('referral_settings');
        Schema::table('inquiries', fn (Blueprint $table) => $table->dropConstrainedForeignId('referred_by_student_id'));
        Schema::table('students', fn (Blueprint $table) => $table->dropConstrainedForeignId('referred_by_student_id'));
    }
};
