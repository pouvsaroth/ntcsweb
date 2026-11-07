<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roles step 5b: HRM > Recruitment's tabs each get their own Add / Update /
 * Delete instead of one recruitment.create|update|delete. Each new permission
 * goes to every role that could do the same thing before (an evaluation of
 * someone else's interview needed recruitment.update); roles are read before
 * sync() drops the old ones.
 */
return new class extends Migration
{
    /** New permission => the old ones that allowed it. */
    private const GRANTS = [
        'manpower-requests.create' => ['recruitment.create'],
        'job-positions.create' => ['recruitment.create'],
        'job-postings.create' => ['recruitment.create'],
        'applicants.create' => ['recruitment.create'],
        'applicant-documents.create' => ['recruitment.create'],
        'interviews.create' => ['recruitment.create'],
        'offer-letters.create' => ['recruitment.create'],
        'manpower-requests.update' => ['recruitment.update'],
        'job-positions.update' => ['recruitment.update'],
        'job-postings.update' => ['recruitment.update'],
        'applicants.update' => ['recruitment.update'],
        'interviews.update' => ['recruitment.update'],
        'interview-evaluations.create' => ['recruitment.update'],
        'offer-letters.update' => ['recruitment.update'],
        'manpower-requests.delete' => ['recruitment.delete'],
        'job-positions.delete' => ['recruitment.delete'],
        'job-postings.delete' => ['recruitment.delete'],
        'applicants.delete' => ['recruitment.delete'],
        'applicant-documents.delete' => ['recruitment.delete'],
        'interviews.delete' => ['recruitment.delete'],
        'interview-evaluations.delete' => ['recruitment.delete'],
        'offer-letters.delete' => ['recruitment.delete'],
    ];

    public function up(): void
    {
        $old = array_values(array_unique(array_merge(...array_values(self::GRANTS))));
        $holders = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->whereIn('permissions.slug', $old)
            ->get(['permissions.slug', 'permission_role.role_id'])
            ->groupBy('slug')
            ->map(fn ($rows) => $rows->pluck('role_id')->all());

        app(PermissionRegistry::class)->sync();

        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::GRANTS))->pluck('id', 'slug');
        $rows = [];
        foreach (self::GRANTS as $new => $from) {
            if (! isset($ids[$new])) {
                continue;
            }
            foreach ($from as $slug) {
                foreach ($holders[$slug] ?? [] as $roleId) {
                    $rows[$roleId.'-'.$ids[$new]] = ['role_id' => $roleId, 'permission_id' => $ids[$new]];
                }
            }
        }
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('permission_role')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        // sync() drops permissions that leave the catalog; nothing to undo by hand.
    }
};
