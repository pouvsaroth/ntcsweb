<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A class's teacher is now a Staff member holding the "Teacher" position
 * (see TeacherPositionSeeder), not a row in the standalone `teachers` table
 * — which the very next migration drops entirely (central-only; it never
 * existed in the tenant database, so `classes`' own create migration
 * already has no foreign key here to drop). Existing `teacher_id` values
 * are nulled out here: a Teacher row's id never corresponds to any Staff
 * row's id, so there is no automatic mapping to carry forward — harmless
 * on a fresh tenant database, where `classes` starts out empty anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('classes')->update(['teacher_id' => null]);
    }

    public function down(): void
    {
        // Nothing to reverse: no foreign key was added in up().
    }
};
