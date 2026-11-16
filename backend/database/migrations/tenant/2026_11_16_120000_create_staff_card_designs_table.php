<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. HRM > Staff cards: each school's own card designs — the card
 * size in mm, a background photo for the front and the back, and the fields
 * placed on each side (JSON: type, position and size in mm, font size,
 * colour, bold, alignment, fixed text). An optional signature / stamp image
 * for the Signature field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_card_designs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->decimal('width_mm', 6, 2);
            $table->decimal('height_mm', 6, 2);
            $table->string('front_background_path')->nullable();
            $table->string('back_background_path')->nullable();
            $table->string('signature_path')->nullable();
            $table->json('front_fields');
            $table->json('back_fields');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'staff_card_designs');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_card_designs');
    }
};
