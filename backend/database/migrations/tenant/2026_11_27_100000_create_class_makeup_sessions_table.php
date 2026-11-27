<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. A whole-class make-up session for a class session that was
 * not held (Teachers > Substitute with no substitute): the date and time the
 * class studies instead, and who teaches it. One per not-held row. See
 * docs/hrm/teachers.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_makeup_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('teacher_substitution_id')->nullable()->unique()->constrained('teacher_substitutions')->cascadeOnDelete();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['class_id', 'date']);
            $table->index(['staff_id', 'date']);
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'class_makeup_sessions');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('class_makeup_sessions');
    }
};
