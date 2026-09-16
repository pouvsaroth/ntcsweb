<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a "Resignation Form" template — same generic ApprovalRequest/
 * FormTemplate mechanism as CHANGE-CLASS/EXTRA-CLASS (see
 * 2026_09_16_030000_seed_change_class_and_extra_class_form_templates), so a
 * staff member can submit one via the admin panel's Staff section (see
 * adminNav.ts's staff group and Forms.vue's `?code=` deep-link handling)
 * with no admin setup required.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $categoryId = $connection->table('form_categories')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('id');

        if ($categoryId === null) {
            return;
        }

        $exists = $connection->table('form_templates')->where('code', 'RESIGNATION')->exists();

        if ($exists) {
            return;
        }

        $connection->table('form_templates')->insert([
            'form_category_id' => $categoryId,
            'code' => 'RESIGNATION',
            'name' => 'Resignation Form',
            'order' => 102,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->table('form_templates')
            ->where('code', 'RESIGNATION')
            ->delete();
    }
};
