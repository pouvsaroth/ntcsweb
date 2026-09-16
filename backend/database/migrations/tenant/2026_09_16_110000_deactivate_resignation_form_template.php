<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates the generic "Resignation Form" template seeded by
 * 2026_09_16_040000_seed_resignation_form_template — superseded by the
 * dedicated ResignationRequest flow (see ResignationFormModal.vue and
 * MyResignationRequestController), which auto-fills the staff member's own
 * name/gender/position instead of asking for free-text subject/details.
 * Deactivated rather than deleted so any ApprovalRequest already submitted
 * against it keeps its history intact — same reasoning as
 * 2026_09_16_080000_deactivate_change_class_and_extra_class_form_templates.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection($this->getConnection())->table('form_templates')
            ->where('code', 'RESIGNATION')
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->table('form_templates')
            ->where('code', 'RESIGNATION')
            ->update(['is_active' => true, 'updated_at' => now()]);
    }
};
