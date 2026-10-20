<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Systems (products) the platform sells (docs/backoffice/stage-9-products.md, 9a):
 * a name and logo + the modules it is built from. Every plan belongs to one.
 * Seeded: School Management (every module, all plans until now) and ERP / HR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('name_km', 100)->nullable();
            $table->text('description')->nullable();
            $table->text('description_km')->nullable();
            $table->string('logo_path')->nullable(); // public disk
            $table->json('modules'); // module codes (App\Platform\Billing\Modules)
            $table->string('status', 20)->default('active'); // active | inactive
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $all = ['academics', 'finance', 'accounting', 'hrm', 'payroll', 'assets', 'library', 'website', 'projects'];
        $schoolId = DB::table('bo_products')->insertGetId([
            'code' => 'school', 'name' => 'School Management System', 'name_km' => 'ប្រព័ន្ធគ្រប់គ្រងសាលា',
            'modules' => json_encode($all), 'status' => 'active', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('bo_products')->insert([
            'code' => 'erp', 'name' => 'ERP / HR System', 'name_km' => 'ប្រព័ន្ធគ្រប់គ្រងធនធានមនុស្ស និងអាជីវកម្ម',
            'modules' => json_encode(['finance', 'accounting', 'hrm', 'payroll', 'assets', 'projects']),
            'status' => 'active', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        Schema::table('bo_plans', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->constrained('bo_products')->restrictOnDelete();
        });
        // Every plan so far sells the school system.
        DB::table('bo_plans')->update(['product_id' => $schoolId]);
    }

    public function down(): void
    {
        Schema::table('bo_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
        Schema::dropIfExists('bo_products');
    }
};
