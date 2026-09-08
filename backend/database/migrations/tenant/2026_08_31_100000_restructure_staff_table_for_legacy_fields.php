<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aligns `staff` with the fields an existing school-management system (a
 * MySQL `t_staff`-shaped table) already tracks, so importing real personnel
 * records is a column-to-column mapping rather than a lossy squeeze into a
 * smaller shape — the same motivation, and the same technique, as the
 * students restructuring migration (see
 * 2026_08_29_010100_restructure_students_table_for_legacy_migration.php):
 *
 *   legacy column                ->  staff column
 *   FirstName / LastName /
 *     OtherName                       first_name / last_name / other_name
 *                                     (new — was one `name` column)
 *   Gender / BirthDate / BirthPlace   gender / date_of_birth / birth_place (new)
 *   NationalID / NationalIDPhoto      national_id / national_id_photo_path (new)
 *   HouseNo / StreetNo / VillageCode  house_no / street_no / village_code (new —
 *                                     free-text village_code, not a foreign
 *                                     key, resolved via /geo/lookup — same
 *                                     convention as students.village_code)
 *   Facebook / Telegram / OtherContact  facebook / telegram / other_contact (new)
 *   Photo                            photo_path (new — see Staff::photoUrl())
 *   —                                profile_color (new — server-generated
 *                                     avatar fallback color, never migrated
 *                                     from any legacy column)
 *
 * Every new column is nullable even where the legacy source marks it
 * required (e.g. a national ID is usually mandatory for a Cambodian staff
 * member) — this platform isn't Cambodia-only, and a future non-Cambodian
 * tenant must not be forced to fill in a national ID or village code.
 * Migrated rows will simply have these populated; new tenants aren't
 * required to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('employee_code');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('other_name')->nullable()->after('last_name');
        });

        // Backfill from the old single `name` column before dropping it:
        // first word -> first_name, the rest -> last_name (empty string when
        // there's no space to split on), same rule the task spec calls for.
        foreach (DB::table('staff')->select('id', 'name')->cursor() as $row) {
            $parts = explode(' ', trim((string) $row->name), 2);

            DB::table('staff')->where('id', $row->id)->update([
                'first_name' => $parts[0] ?? '',
                'last_name' => $parts[1] ?? '',
            ]);
        }

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('name');

            $table->string('gender', 10)->nullable()->after('other_name');
            $table->date('date_of_birth')->nullable()->after('gender');
            $table->string('birth_place')->nullable()->after('date_of_birth');

            $table->string('national_id')->nullable()->after('email');
            // Storage-relative path, not a URL — same convention as
            // Student::photo_path; resolved at read time via
            // Staff::nationalIdPhotoUrl().
            $table->string('national_id_photo_path', 500)->nullable()->after('national_id');

            $table->string('house_no', 10)->nullable()->after('phone');
            $table->string('street_no', 10)->nullable()->after('house_no');
            $table->string('village_code', 20)->nullable()->after('street_no');

            $table->string('facebook')->nullable()->after('village_code');
            $table->string('telegram')->nullable()->after('facebook');
            $table->string('other_contact')->nullable()->after('telegram');

            $table->string('photo_path', 500)->nullable()->after('other_contact');

            // Deterministic avatar-fallback color, picked once at creation
            // from a small fixed palette based on a hash of the staff
            // member's full name — see StaffController::store(). Never a
            // user-editable field.
            $table->string('profile_color', 20)->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            // Nullable even though the original column wasn't: a straight
            // rollback can't know what to backfill `name` with from
            // first_name/last_name for any rows inserted in the meantime.
            $table->string('name')->nullable()->after('employee_code');
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn([
                'first_name', 'last_name', 'other_name',
                'gender', 'date_of_birth', 'birth_place',
                'national_id', 'national_id_photo_path',
                'house_no', 'street_no', 'village_code',
                'facebook', 'telegram', 'other_contact',
                'photo_path', 'profile_color',
            ]);
        });
    }
};
