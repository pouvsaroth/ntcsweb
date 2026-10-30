<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. HRM > Staff > Emergency Contacts and Family Members
 * (docs/hrm/staff-profile-tabs.md): many rows per staff member each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('name', 150);
            // father | mother | spouse | sibling | child | relative | friend | other
            $table->string('relationship', 20);
            $table->string('phone', 30);
            $table->string('alt_phone', 30)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index('staff_id');
        });

        Schema::create('staff_family_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('name', 150);
            // spouse | child | father | mother | sibling | other
            $table->string('relationship', 20);
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('occupation', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index('staff_id');
        });

        $db = DB::connection($this->getConnection());
        foreach (['staff_emergency_contacts', 'staff_family_members'] as $table) {
            SchoolTables::secure($db, $table);
        }
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_family_members');
        Schema::dropIfExists('staff_emergency_contacts');
    }
};
