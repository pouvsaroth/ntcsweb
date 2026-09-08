<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A department/category grouping in the eApprovals "Forms" catalog (e.g.
 * "General", "Finance", "IT") — see FormTemplate, which each belongs to one
 * of these. Lives in the school's own database (see BelongsToTenant's
 * docblock) — no `tenant_id` column; every row here already belongs to
 * whichever school's database it's in. Seeded with a single starter
 * "General" category so the Forms catalog isn't empty on day one — a school
 * renames or adds more of its own afterward through the admin screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_categories', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('order');
        });

        DB::connection($this->getConnection())->table('form_categories')->insert([
            'name' => 'General',
            'order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('form_categories');
    }
};
