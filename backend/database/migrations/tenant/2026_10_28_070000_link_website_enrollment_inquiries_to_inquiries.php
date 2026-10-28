<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Website "Register" submissions (enrollment_inquiries) now also appear in
 * Front Office > Inquiry with source Website. New ones are added when they
 * are submitted (EnrollmentInquiryController); this copies the ones already
 * there, once, linked by enrollment_inquiry_id so they are never copied twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inquiries', 'enrollment_inquiry_id')) {
            Schema::table('inquiries', function (Blueprint $table) {
                $table->unsignedBigInteger('enrollment_inquiry_id')->nullable()->after('student_id');
                $table->index('enrollment_inquiry_id');
            });
        }

        // Migrations may run as a superuser, which skips the per-school row
        // policy — so every table here is filtered to this school explicitly.
        $current = SchoolTables::CURRENT;
        DB::statement(<<<SQL
            INSERT INTO inquiries (inquiry_date, name, phone, email, interested_in, source, status, note, enrollment_inquiry_id, created_at, updated_at)
            SELECT e.created_at::date, left(e.name, 150), left(e.phone, 40), left(e.email, 150), left(p.title, 255),
                   'website', 'new', e.message, e.id, e.created_at, now()
            FROM enrollment_inquiries e
            LEFT JOIN programs p ON p.id = e.program_id AND p.tenant_id = e.tenant_id
            WHERE e.tenant_id = {$current}
              AND NOT EXISTS (
                  SELECT 1 FROM inquiries i WHERE i.enrollment_inquiry_id = e.id AND i.tenant_id = {$current}
              )
            SQL);
    }

    public function down(): void
    {
        $current = SchoolTables::CURRENT;
        DB::statement("DELETE FROM inquiries WHERE enrollment_inquiry_id IS NOT NULL AND source = 'website' AND status = 'new' AND tenant_id = {$current}");

        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropIndex(['enrollment_inquiry_id']);
            $table->dropColumn('enrollment_inquiry_id');
        });
    }
};
