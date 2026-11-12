<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. HRM > Attendance & Time > Geofences: the places staff may
 * check in / out from — a circle (centre + radius) or a polygon (corners).
 * staff_geofence_rules: a staff member who may check in anywhere, or only
 * at some zones. Each check-in / out keeps the zone it was made in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geofences', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('shape', 10); // circle | polygon
            // The circle's centre; a polygon's middle (for the map and "nearest").
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_m')->nullable();
            $table->json('points')->nullable(); // polygon: [[lat, lng], ...]
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_geofence_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->unique()->constrained('staff')->cascadeOnDelete();
            $table->string('mode', 10); // anywhere | zones
            $table->json('geofence_ids')->nullable();
            $table->timestamps();
        });

        Schema::table('staff_attendances', function (Blueprint $table) {
            $table->string('check_in_geofence')->nullable();
            $table->string('check_out_geofence')->nullable();
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'geofences');
        SchoolTables::secure($db, 'staff_geofence_rules');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::table('staff_attendances', function (Blueprint $table) {
            $table->dropColumn(['check_in_geofence', 'check_out_geofence']);
        });
        Schema::dropIfExists('staff_geofence_rules');
        Schema::dropIfExists('geofences');
    }
};
