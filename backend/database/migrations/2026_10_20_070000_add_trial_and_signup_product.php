<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Website and sign-up per system (docs/backoffice/stage-9-products.md, 9c):
 * a system's free-trial plan (none = no free trial; the school system falls
 * back to Billing → Settings' trial plan), and the system a sign-up is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bo_products', function (Blueprint $table) {
            $table->foreignId('trial_plan_id')->nullable()->constrained('bo_plans')->nullOnDelete();
        });

        Schema::table('bo_signups', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->constrained('bo_products')->nullOnDelete();
        });
        $school = DB::table('bo_products')->where('code', 'school')->value('id');
        DB::table('bo_signups')->update(['product_id' => $school]);
    }

    public function down(): void
    {
        Schema::table('bo_signups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
        Schema::table('bo_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trial_plan_id');
        });
    }
};
