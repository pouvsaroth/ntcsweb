<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A visitor's "Register" submission from the public website — a lead, not a
 * Student/Enrollment record. Turning one into an actual enrolled student
 * still goes through the existing admin Student-creation flow; this table
 * only captures the initial interest so the school has something to follow
 * up on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 32);
            $table->string('email')->nullable();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_inquiries');
    }
};
