<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Front Office > Appointments and Visitors. An appointment is a booked
 * visit; checking it in creates the visitor record (visitors.appointment_id).
 * Walk-ins are visitor records with no appointment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('organization', 150)->nullable();
            $table->string('purpose', 255);
            $table->date('appointment_date');
            $table->time('start_time');
            $table->time('end_time')->nullable();
            // The staff member they come to see.
            $table->foreignId('host_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('status', 20)->default('scheduled');
            $table->text('note')->nullable();
            // No DB-level foreign key — users live in the central database.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['appointment_date', 'start_time']);
        });

        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('id_number', 50)->nullable();
            $table->string('organization', 150)->nullable();
            $table->string('purpose', 255);
            $table->foreignId('host_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->unsignedSmallInteger('people_count')->default(1);
            $table->string('badge_no', 30)->nullable();
            $table->timestamp('check_in_at');
            $table->timestamp('check_out_at')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('check_in_at');
        });

        $db = DB::connection();
        SchoolTables::secure($db, 'appointments');
        SchoolTables::secure($db, 'visitors');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('visitors');
        Schema::dropIfExists('appointments');
    }
};
