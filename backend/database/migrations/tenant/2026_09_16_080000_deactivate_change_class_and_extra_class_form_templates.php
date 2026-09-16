<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates the two form templates seeded by
 * 2026_09_16_030000_seed_change_class_and_extra_class_form_templates — no
 * longer offered from the public "Document and Form" menu or the Forms
 * catalog (see publicNav.ts), per direct request. Deactivated rather than
 * deleted so any ApprovalRequest already submitted against either keeps its
 * history intact (form_templates rows cascade-delete their
 * approval_requests — see that table's migration), and a school admin can
 * still see or re-enable them from the Form Templates admin screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection($this->getConnection())->table('form_templates')
            ->whereIn('code', ['CHANGE-CLASS', 'EXTRA-CLASS'])
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->table('form_templates')
            ->whereIn('code', ['CHANGE-CLASS', 'EXTRA-CLASS'])
            ->update(['is_active' => true, 'updated_at' => now()]);
    }
};
