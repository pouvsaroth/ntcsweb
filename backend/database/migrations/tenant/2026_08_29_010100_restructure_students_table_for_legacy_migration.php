<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aligns `students` with the fields an existing school-management system
 * (a MySQL `t_student` table) already tracks, so importing real records is a
 * column-to-column mapping rather than a lossy squeeze into a smaller shape:
 *
 *   t_student column   ->  students column
 *   StudentID               student_code       (already existed)
 *   FirstName / LastName    first_name / last_name   (new — was one `name` column)
 *   EnglishName             english_name       (new)
 *   Gender / BirthDate      gender / date_of_birth   (already existed)
 *   HouseNo / StreetNo /
 *     VillageCode / OtherAddress   house_no / street_no / village_code / other_address
 *                                  (new — was one free-text `address` column)
 *   StudentPhone / Email    phone / email      (already existed)
 *   StudentFacebook/Telegram  facebook / telegram   (new)
 *   Photo                   photo_path         (new — see Student::photoUrl())
 *
 * Every new column is nullable even where the source system marks it
 * required (e.g. `VillageCode NOT NULL`) — this platform isn't
 * Cambodia-only, and a future non-Cambodian tenant must not be forced to
 * fill in a village code. Migrated rows will simply have it populated;
 * new tenants aren't required to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('student_code');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('english_name')->nullable()->after('last_name');

            $table->string('house_no', 10)->nullable()->after('phone');
            $table->string('street_no', 10)->nullable()->after('house_no');
            $table->string('village_code', 20)->nullable()->after('street_no');
            $table->string('other_address', 150)->nullable()->after('village_code');

            $table->string('facebook')->nullable()->after('email');
            $table->string('telegram')->nullable()->after('facebook');

            // Storage-relative path, not a URL — same convention as
            // HomeSlide::imageUrl(); resolved at read time via
            // Student::photoUrl() so switching storage disks later needs no
            // data migration.
            $table->string('photo_path', 500)->nullable()->after('telegram');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['name', 'address']);
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Nullable even though the original column wasn't: a straight
            // rollback can't know what to backfill `name` with from
            // first_name/last_name for any rows inserted in the meantime.
            $table->string('name')->nullable()->after('student_code');
            $table->string('address')->nullable()->after('phone');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'first_name', 'last_name', 'english_name',
                'house_no', 'street_no', 'village_code', 'other_address',
                'facebook', 'telegram', 'photo_path',
            ]);
        });
    }
};
