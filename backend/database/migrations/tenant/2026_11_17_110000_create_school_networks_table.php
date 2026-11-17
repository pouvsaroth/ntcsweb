<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. HRM > Attendance & Time > Geofences > School Wi-Fi: the
 * school's internet addresses. A phone on the school Wi-Fi reaches the
 * server from one of them, so a check-in / out from there needs no
 * location. Each check-in / out keeps how it was allowed (via).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_networks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // One address (203.0.113.7) or a range (2001:db8:1:2::/64, 203.0.113.0/29).
            $table->string('ip_range', 64);
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::table('staff_attendances', function (Blueprint $table) {
            $table->string('check_in_via', 10)->nullable(); // location | wifi
            $table->string('check_out_via', 10)->nullable();
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'school_networks');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::table('staff_attendances', function (Blueprint $table) {
            $table->dropColumn(['check_in_via', 'check_out_via']);
        });
        Schema::dropIfExists('school_networks');
    }
};
