<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data sync: replaces this tenant's entire course_packages table
 * with the corrected catalog from local dev (fixed id numbering, and typo'd
 * codes like OFFICEO2/VD004/REPOLI/REPOI corrected to OFFICE02/VDO04/
 * REP01/REP02), then remaps course_package_id in enrollments, videos,
 * course_package_book and class_course_package from whatever this server's
 * rows currently carry to the new ids — matched by (possibly typo'd) code,
 * not by raw id, since a school's live ids can't be assumed to already
 * match local's.
 *
 * Guarded to only ever run against the NewTech database: this table's data
 * (course names, prices, product_id links) is specific to that one school,
 * and this migration ships through database/migrations/tenant, which
 * ProvisionAllTenantDatabasesCommand applies to every active tenant on
 * every deploy — without the guard this would wipe every other school's
 * course catalog.
 */
return new class extends Migration
{
    private const TENANT_DATABASE = 'tenant_newtech';

    public function up(): void
    {
        if (DB::connection()->getDatabaseName() !== self::TENANT_DATABASE) {
            return;
        }

        DB::statement('CREATE TEMP TABLE server_snapshot AS SELECT id AS old_id, code AS old_code FROM course_packages');

        DB::statement('CREATE TEMP TABLE code_alias (alias varchar(32), final_code varchar(32))');
        DB::statement(<<<'SQL'
            INSERT INTO code_alias (alias, final_code) VALUES
            ('OFFICE01','OFFICE01'),
            ('OFFICEO2','OFFICE02'), ('OFFICE02','OFFICE02'),
            ('OFFICE03','OFFICE03'),
            ('OFFICE04','OFFICE04'),
            ('OFFICEO5','OFFICE05'), ('OFFICE05','OFFICE05'),
            ('OFFICEO6','OFFICE06'), ('OFFICE06','OFFICE06'),
            ('OFFICEO7','OFFICE07'), ('OFFICE07','OFFICE07'),
            ('OFFICEO8','OFFICE08'), ('OFFICE08','OFFICE08'),
            ('OFFICE09','OFFICE09'), ('OFFICE10','OFFICE10'),
            ('OFFICE11','OFFICE11'), ('OFFICE12','OFFICE12'),
            ('DES01','DES01'), ('DES02','DES02'), ('DES03','DES03'),
            ('DES04','DES04'), ('DES05','DES05'), ('DES06','DES06'),
            ('DES07','DES07'), ('DES08','DES08'),
            ('VDO01','VDO01'), ('VDO02','VDO02'), ('VDO03','VDO03'),
            ('VD004','VDO04'), ('VDO04','VDO04'),
            ('REPOLI','REP01'), ('REP01','REP01'),
            ('REPOI','REP02'), ('REP02','REP02'),
            ('CODE01','CODE01'), ('CODE02','CODE02'), ('CODE03','CODE03'),
            ('CODE04','CODE04'), ('CODE05','CODE05'), ('CODE06','CODE06'),
            ('CODE07','CODE07'), ('CODE08','CODE08'), ('CODE09','CODE09'),
            ('CODE010','CODE010'), ('CODE011','CODE011'), ('CODE012','CODE012'),
            ('CODE013','CODE013'), ('CODE014','CODE014'), ('CODE015','CODE015'),
            ('CODE16','CODE16'), ('CODE17','CODE17'), ('CODE18','CODE18'),
            ('CODE19','CODE19'), ('CODE20','CODE20'), ('CODE21','CODE21')
        SQL);

        // Abort (and roll back) if this server's products table is missing
        // any product_id the incoming course_packages rows reference.
        DB::statement(<<<'SQL'
            DO $$
            DECLARE missing int;
            BEGIN
              SELECT count(*) INTO missing FROM (VALUES
                (1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),(12),(13),(14),(15),(16),
                (17),(18),(19),(20),(21),(22),(23),(24),(25),(26),(27),(28),(29),(30),
                (31),(32),(33),(34),(35),(36),(37)
              ) AS wanted(pid)
              WHERE NOT EXISTS (SELECT 1 FROM products p WHERE p.id = wanted.pid);
              IF missing > 0 THEN
                RAISE EXCEPTION 'Aborting course_packages sync: % product_id(s) referenced by the incoming data do not exist in this server''s products table', missing;
              END IF;
            END $$
        SQL);

        DB::statement('DELETE FROM course_packages');
        DB::statement('ALTER TABLE course_packages DISABLE TRIGGER ALL');

        DB::statement(<<<'SQL'
            INSERT INTO course_packages (id, code, name, academic_program_id, description, price, duration, product_id, is_active, created_at, updated_at, deleted_at, fee_monthly, fee_term, fee_video, fee_monthly_online, fee_term_online, currency, show_on_website, show_in_popular, thumbnail_path, show_videos) VALUES
            (48, 'OFFICE09', 'Quickbook', 1, NULL, 0.00, NULL, NULL, true, '2026-09-09 07:58:08', '2026-09-09 07:58:08', NULL, NULL, NULL, NULL, NULL, NULL, 'USD', false, false, NULL, false),
            (65, 'CODE16', 'PHP and Laravel Project For Work', 1, NULL, 0.00, NULL, NULL, true, '2026-09-09 07:58:08', '2026-09-09 07:58:08', NULL, NULL, NULL, NULL, NULL, NULL, 'USD', false, false, NULL, false),
            (66, 'OFFICE10', 'Word + PowerPoint 2021', 1, NULL, 0.00, NULL, NULL, true, '2026-09-09 07:58:08', '2026-09-09 07:58:08', NULL, NULL, NULL, NULL, NULL, NULL, 'USD', false, false, NULL, false),
            (67, 'OFFICE11', 'Excel + PowerPoint 2021', 1, NULL, 0.00, NULL, NULL, true, '2026-09-09 07:58:08', '2026-09-09 07:58:08', NULL, NULL, NULL, NULL, NULL, NULL, 'USD', false, false, NULL, false),
            (68, 'OFFICE12', 'Word + Excel + PowerPoint 2021', 1, NULL, 0.00, NULL, NULL, true, '2026-09-09 07:58:08', '2026-09-09 07:58:08', NULL, NULL, NULL, NULL, NULL, NULL, 'USD', false, false, NULL, false),
            (69, 'CODE17', 'Web Application For Work V4-0', 1, NULL, 0.00, NULL, NULL, true, '2026-09-09 07:58:08', '2026-09-09 07:58:08', NULL, NULL, NULL, NULL, NULL, NULL, 'USD', false, false, NULL, false),
            (71, 'CODE18', 'Web Application For Work V4-2', 1, NULL, 0.00, NULL, NULL, true, '2026-09-09 07:58:08', '2026-09-09 07:58:08', NULL, NULL, NULL, NULL, NULL, NULL, 'USD', false, false, NULL, false),
            (72, 'CODE19', 'Web Application For Work V4-3', 1, NULL, 0.00, NULL, NULL, true, '2026-09-09 07:58:08', '2026-09-09 07:58:08', NULL, NULL, NULL, NULL, NULL, NULL, 'USD', false, false, NULL, false),
            (73, 'CODE20', 'Web Application For Work V4-4', 1, NULL, 0.00, NULL, NULL, true, '2026-09-09 07:58:08', '2026-09-09 07:58:08', NULL, NULL, NULL, NULL, NULL, NULL, 'USD', false, false, NULL, false),
            (75, 'CODE21', 'សរសេរប្រពន្ធគ្រប់គ្រងទិន្នន័យ និង Adobe Illustrator', 1, NULL, 0.00, NULL, NULL, true, '2026-09-09 07:58:08', '2026-09-09 07:58:08', NULL, NULL, NULL, NULL, NULL, NULL, 'USD', false, false, NULL, false),
            (2, 'OFFICE01', 'Word 2024', 1, NULL, 10.00, '-1', 1, true, '2026-09-03 22:07:39', '2026-09-05 17:28:42', NULL, 10.00, 20.00, 5.00, 10.00, 20.00, 'USD', true, true, 'tenants/1/course-package-thumbnails/owdw71jr7Sv5RH8CkFEZdORhU3J4OX9X5sGr78aW.png', true),
            (3, 'OFFICE02', 'Excel 2024', 1, NULL, 10.00, '-1', 2, true, '2026-09-03 22:09:48', '2026-09-05 14:30:47', NULL, 10.00, 20.00, 5.00, 10.00, 20.00, 'USD', true, true, 'tenants/1/course-package-thumbnails/5dRTOaZqpEZpQAJqlSz0fXm1QjUC1PNwvT6LSejx.png', false),
            (5, 'OFFICE03', 'PowerPoint 2024', 1, NULL, 10.00, NULL, 3, true, '2026-09-04 23:42:58', '2026-09-04 23:42:58', NULL, 10.00, 10.00, 5.00, 10.00, 8.00, 'USD', true, true, 'tenants/1/course-package-thumbnails/lQJmuRTiAH9pUn9KFdffvsUnqPEu65HhKB63D4SX.png', true),
            (13, 'DES01', 'Photoshop 2024 I', 1, NULL, 12.00, NULL, 4, true, '2026-09-05 13:58:21', '2026-09-05 13:58:21', NULL, 12.00, 20.00, 10.00, 12.00, 15.00, 'USD', true, true, NULL, true),
            (7, 'OFFICE04', 'Access2024(2)', 1, NULL, 10.00, NULL, 5, true, '2026-09-05 14:17:04', '2026-09-06 14:27:39', NULL, 10.00, 20.00, 10.00, 10.00, 20.00, 'USD', false, false, NULL, false),
            (4, 'OFFICE05', 'Excel Advance 2024', 1, NULL, 10.00, NULL, 6, true, '2026-09-05 14:21:49', '2026-09-05 14:29:46', NULL, 10.00, 20.00, 10.00, 10.00, 20.00, 'USD', true, true, NULL, true),
            (56, 'OFFICE06', 'Excel VBA 2024', 1, NULL, 10.00, NULL, 7, true, '2026-09-05 14:23:57', '2026-09-05 14:29:33', NULL, 10.00, 40.00, 15.00, 10.00, 40.00, 'USD', true, true, NULL, true),
            (6, 'OFFICE07', 'Access 2024(1)', 1, NULL, 10.00, NULL, 8, true, '2026-09-05 14:26:19', '2026-09-06 14:27:28', NULL, 10.00, 20.00, 10.00, 10.00, 17.00, 'USD', false, false, NULL, false),
            (12, 'OFFICE08', 'Internet & Email', 1, NULL, 10.00, NULL, 9, true, '2026-09-05 14:29:18', '2026-09-07 21:04:06', NULL, 10.00, 10.00, 5.00, 10.00, 5.00, 'USD', false, false, NULL, false),
            (74, 'DES08', 'Photoshop 2024 II', 1, NULL, 12.00, NULL, 10, true, '2026-09-05 14:41:49', '2026-09-05 14:50:02', '2026-09-05 14:50:02', 12.00, 20.00, 10.00, 12.00, 20.00, 'USD', true, true, NULL, true),
            (77, 'DES02', 'Canva', 1, NULL, 12.00, NULL, 11, true, '2026-09-05 14:43:13', '2026-09-05 14:43:13', NULL, 12.00, 25.00, 10.00, 12.00, 25.00, 'USD', true, true, NULL, true),
            (15, 'DES03', 'CorelDraw 2024', 1, NULL, 12.00, NULL, 12, true, '2026-09-05 14:45:22', '2026-09-05 14:45:22', NULL, 12.00, 25.00, 12.00, 12.00, 25.00, 'USD', true, true, NULL, true),
            (14, 'DES04', 'lIustrator 2024', 1, NULL, 12.00, NULL, 13, true, '2026-09-05 14:47:18', '2026-09-05 14:47:18', NULL, 12.00, 25.00, 12.00, 12.00, 25.00, 'USD', true, true, NULL, true),
            (16, 'DES05', 'lndesign 2024', 1, NULL, 12.00, NULL, 14, true, '2026-09-05 14:51:58', '2026-09-07 21:04:22', NULL, 12.00, 25.00, 12.00, 12.00, 25.00, 'USD', false, false, NULL, false),
            (17, 'DES06', 'Flash 2024', 1, NULL, 12.00, NULL, 15, true, '2026-09-05 14:53:05', '2026-09-05 14:53:05', NULL, 12.00, 25.00, 12.00, 12.00, 25.00, 'USD', true, true, NULL, true),
            (23, 'DES07', 'Sketchup 2024', 1, NULL, 12.00, NULL, 16, true, '2026-09-05 14:54:14', '2026-09-05 14:54:14', NULL, 12.00, 25.00, 12.00, 12.00, 25.00, 'USD', true, true, NULL, true),
            (25, 'VDO01', 'Audition 2024', 1, NULL, 12.00, NULL, 17, true, '2026-09-05 14:58:22', '2026-09-05 14:58:22', NULL, 12.00, 20.00, 9.00, 12.00, 20.00, 'USD', true, true, NULL, true),
            (47, 'VDO02', 'After Effect 2024', 1, NULL, 12.00, NULL, 18, true, '2026-09-05 14:59:55', '2026-09-05 14:59:55', NULL, 12.00, 25.00, 12.00, 12.00, 25.00, 'USD', true, true, NULL, true),
            (30, 'VDO03', 'Premier 2024', 1, NULL, 12.00, NULL, 19, true, '2026-09-05 15:02:16', '2026-09-05 15:02:16', NULL, 12.00, 25.00, 12.00, 12.00, 25.00, 'USD', true, true, NULL, true),
            (29, 'VDO04', 'Sony Vegas 2024', 1, NULL, 12.00, NULL, 20, true, '2026-09-05 15:03:38', '2026-09-07 21:05:03', NULL, 12.00, 25.00, 12.00, 12.00, 25.00, 'USD', false, false, NULL, false),
            (35, 'REP01', 'តបណ្តាញកុំព្យូទ័រ(មធ្យម)', 1, NULL, 80.00, NULL, 21, true, '2026-09-05 15:12:53', '2026-09-05 15:12:53', NULL, NULL, 80.00, NULL, NULL, 80.00, 'USD', true, true, NULL, true),
            (46, 'REP02', 'ជួសជុលកុំព្យូទ័រ(មធ្យម)', 1, NULL, 40.00, NULL, 22, true, '2026-09-05 15:14:58', '2026-09-05 15:14:58', NULL, NULL, 40.00, NULL, NULL, 40.00, 'USD', true, true, NULL, true),
            (51, 'CODE01', 'HTML5 + CSS3', 1, NULL, 30.00, NULL, 23, true, '2026-09-05 15:53:59', '2026-09-05 15:53:59', NULL, NULL, 30.00, 12.00, NULL, 30.00, 'USD', true, true, NULL, true),
            (62, 'CODE02', 'JAVASCRIPT', 1, NULL, 30.00, NULL, 24, true, '2026-09-05 15:56:03', '2026-09-05 15:56:03', NULL, NULL, 30.00, 12.00, NULL, 30.00, 'USD', true, true, NULL, true),
            (54, 'CODE03', 'PHP & MYSQL', 1, NULL, 40.00, NULL, 25, true, '2026-09-05 15:58:03', '2026-09-05 15:58:03', NULL, NULL, 40.00, 12.00, NULL, 40.00, 'USD', true, true, NULL, true),
            (53, 'CODE04', 'C', 1, NULL, 30.00, NULL, 26, true, '2026-09-05 15:59:00', '2026-09-05 15:59:00', NULL, NULL, 30.00, 12.00, NULL, 30.00, 'USD', true, true, NULL, true),
            (52, 'CODE05', 'C++', 1, NULL, 30.00, NULL, 27, true, '2026-09-05 16:00:34', '2026-09-07 21:03:54', NULL, NULL, 30.00, 12.00, NULL, 30.00, 'USD', false, false, NULL, false),
            (55, 'CODE06', 'Flutter', 1, NULL, 50.00, NULL, 28, true, '2026-09-05 16:03:56', '2026-09-05 16:03:56', NULL, NULL, 50.00, 12.00, NULL, 50.00, 'USD', true, true, NULL, true),
            (39, 'CODE07', 'Python', 1, NULL, 50.00, NULL, 29, true, '2026-09-05 16:05:26', '2026-09-05 16:05:26', NULL, NULL, 50.00, 12.00, NULL, 50.00, 'USD', true, true, NULL, true),
            (64, 'CODE08', 'SQL + C#', 1, NULL, 50.00, NULL, 30, true, '2026-09-05 16:14:48', '2026-09-05 16:14:48', NULL, NULL, 50.00, 12.00, NULL, 50.00, 'USD', true, true, NULL, true),
            (38, 'CODE09', 'JAVA', 1, NULL, 50.00, NULL, 31, true, '2026-09-05 16:16:11', '2026-09-05 16:16:11', NULL, NULL, 50.00, 12.00, NULL, 50.00, 'USD', true, true, NULL, true),
            (60, 'CODE010', 'ASP.NET', 1, NULL, 50.00, NULL, 32, true, '2026-09-05 16:20:49', '2026-09-05 16:20:49', NULL, NULL, 50.00, 12.00, NULL, 50.00, 'USD', false, false, NULL, false),
            (61, 'CODE011', 'VB.NET', 1, NULL, 50.00, NULL, 33, true, '2026-09-05 16:24:30', '2026-09-07 21:05:37', '2026-09-07 21:05:37', NULL, 50.00, 12.00, NULL, 50.00, 'USD', false, false, NULL, false),
            (76, 'CODE012', 'VB.NET', 1, NULL, 50.00, NULL, 34, true, '2026-09-05 16:25:58', '2026-09-05 16:43:16', NULL, NULL, 50.00, 12.00, NULL, 50.00, 'USD', false, false, NULL, false),
            (43, 'CODE013', 'សរសេរកម្នវិធីទូរស័ព្ទ', 1, NULL, 200.00, NULL, 35, true, '2026-09-05 16:28:44', '2026-09-05 16:28:44', NULL, NULL, 200.00, NULL, NULL, 200.00, 'USD', false, false, NULL, false),
            (45, 'CODE014', 'កម្នវិធីគ្រុបគ្រងទិន្នន័យ', 1, NULL, 150.00, NULL, 36, true, '2026-09-05 16:32:50', '2026-09-05 16:32:50', NULL, NULL, 150.00, NULL, NULL, 150.00, 'USD', false, false, NULL, false),
            (44, 'CODE015', 'សរសេរវេបសាយ', 1, NULL, 150.00, NULL, 37, true, '2026-09-05 16:35:48', '2026-09-05 16:35:48', NULL, NULL, 150.00, NULL, NULL, 150.00, 'USD', false, false, NULL, false)
        SQL);

        DB::statement('ALTER TABLE course_packages ENABLE TRIGGER ALL');
        DB::statement("SELECT pg_catalog.setval('course_packages_id_seq', 77, true)");

        foreach (['enrollments', 'videos', 'course_package_book', 'class_course_package'] as $table) {
            DB::statement(<<<SQL
                UPDATE {$table} t
                SET course_package_id = cp.id
                FROM server_snapshot s
                JOIN code_alias a ON upper(trim(s.old_code)) = a.alias
                JOIN course_packages cp ON cp.code = a.final_code
                WHERE t.course_package_id = s.old_id
            SQL);
        }
    }

    public function down(): void
    {
        // Nothing to reverse: this is a one-way sync of course_packages
        // (and its dependents' course_package_id) from local dev's already-
        // corrected data. There's no prior server state worth restoring
        // automatically — see the pre-migration pg_dump backup taken before
        // this was first applied if a manual rollback is ever needed.
    }
};
