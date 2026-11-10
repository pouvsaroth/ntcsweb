<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Invoices > Discount codes, simpler:
 *  - The expiry date is optional (empty = no end).
 *  - No courses and no total use limit any more.
 *  - No "who can use it": a discount (main code) works for every student,
 *    and assigning it to a student makes that student their own code,
 *    STUDENTCODE_CODE_ddmmyy (parent_id = the main code).
 * Students already given a personal code get their own code now, dated the
 * day they were given it; the old code becomes a main code, set inactive.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection($this->getConnection());

        Schema::table('discount_codes', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('discount_codes')->restrictOnDelete();
            $table->string('code', 80)->change();
            $table->date('expires_on')->nullable()->change();
        });
        Schema::table('discount_codes', function (Blueprint $table) {
            $table->dropColumn('max_uses');
        });
        Schema::dropIfExists('discount_code_course_package');

        $now = now();
        $taken = $db->table('discount_codes')->pluck('code')->flip()->all();
        $personal = $db->table('discount_codes')->whereNull('parent_id')->where('audience', 'personal')->orderBy('id')->get();

        foreach ($personal as $main) {
            $given = $db->table('discount_code_student')
                ->join('students', 'students.id', '=', 'discount_code_student.student_id')
                ->where('discount_code_student.discount_code_id', $main->id)
                ->orderBy('discount_code_student.id')
                ->get(['students.id as student_id', 'students.student_code', 'discount_code_student.created_at']);

            foreach ($given as $row) {
                $tail = strtoupper((string) preg_replace('/[^A-Za-z0-9-]/', '', (string) $row->student_code)) ?: 'S'.$row->student_id;
                $base = $tail.'_'.$main->code.'_'.Carbon::parse($row->created_at ?? $now)->format('dmy');
                $code = $base;
                for ($n = 2; isset($taken[$code]); $n++) {
                    $code = $base.'-'.$n;
                }
                $taken[$code] = true;

                $id = $db->table('discount_codes')->insertGetId([
                    'parent_id' => $main->id,
                    'code' => $code,
                    'name' => $main->name,
                    'audience' => 'personal',
                    'discount_type' => $main->discount_type,
                    'value' => $main->value,
                    'max_discount' => $main->max_discount,
                    'currency' => $main->currency,
                    'starts_on' => $main->starts_on,
                    'expires_on' => $main->expires_on,
                    'status' => $main->status,
                    'notes' => $main->notes,
                    'created_by' => $main->created_by,
                    'created_at' => $row->created_at ?? $now,
                    'updated_at' => $now,
                ]);
                $db->table('discount_code_student')->insert([
                    'discount_code_id' => $id,
                    'student_id' => $row->student_id,
                    'created_at' => $row->created_at ?? $now,
                    'updated_at' => $now,
                ]);
            }

            // It was only for the students given it, so it is not opened to
            // everyone: set inactive (staff can turn it on for every student).
            $db->table('discount_code_student')->where('discount_code_id', $main->id)->delete();
            $db->table('discount_codes')->where('id', $main->id)->update(['audience' => 'public', 'status' => 'inactive', 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // The students' own codes and the dropped settings are not put back.
    }
};
