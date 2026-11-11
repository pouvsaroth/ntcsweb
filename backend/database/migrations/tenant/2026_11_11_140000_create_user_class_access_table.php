<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Users > Extra classes: classes a user whose role shows only
 * assigned classes may also see, besides the classes they teach.
 * `user_id` points to the central users table (no FK across databases).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_class_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'class_id']);
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'user_class_access');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_class_access');
    }
};
