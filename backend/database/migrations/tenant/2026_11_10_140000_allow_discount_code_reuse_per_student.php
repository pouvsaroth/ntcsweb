<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-owned. Invoices > Discount codes: a student may use the same code
 * on every enrollment (was once per student), so the one-use-per-student
 * index goes. The code's own total limit (max_uses) still applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection($this->getConnection())->statement('DROP INDEX IF EXISTS discount_code_redemptions_one_per_student');
    }

    public function down(): void
    {
        // Students may already have used a code more than once; leave it off.
    }
};
