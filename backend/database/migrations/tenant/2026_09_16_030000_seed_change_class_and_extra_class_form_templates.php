<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds two default form templates — "Request for Change Class" and
 * "Request for Extra Classes" — under the earliest form category (normally
 * "General", seeded by 2026_09_07_010000_create_form_categories_table) so
 * both are available in the Forms catalog out of the box, the same way the
 * public "Document and Form" menu's other Form items are always there
 * without needing a school admin to configure anything first (see
 * publicNav.ts's documentsAndFormLinks). A school admin can still rename,
 * recategorize, or deactivate either afterward through the Form Templates
 * admin screen — this only guarantees they exist on day one. Uses the query
 * builder (not the FormTemplate/FormCategory models) since a migration must
 * stay correct even if those models change shape later.
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

        foreach ([
            ['code' => 'CHANGE-CLASS', 'name' => 'Request for Change Class', 'order' => 100],
            ['code' => 'EXTRA-CLASS', 'name' => 'Request for Extra Classes', 'order' => 101],
        ] as $template) {
            $exists = $connection->table('form_templates')->where('code', $template['code'])->exists();

            if ($exists) {
                continue;
            }

            $connection->table('form_templates')->insert([
                'form_category_id' => $categoryId,
                'code' => $template['code'],
                'name' => $template['name'],
                'order' => $template['order'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->table('form_templates')
            ->whereIn('code', ['CHANGE-CLASS', 'EXTRA-CLASS'])
            ->delete();
    }
};
