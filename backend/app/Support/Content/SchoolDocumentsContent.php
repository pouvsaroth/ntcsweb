<?php

declare(strict_types=1);

namespace App\Support\Content;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Exactly two fixed documents per school — School Regulation and Student
 * Attendance Policy — stored in `tenants.settings->documents`, same
 * single-JSON-blob-per-tenant shape as AboutPageContent rather than a
 * Document model/CRUD table, since there is never more than one of each.
 */
final class SchoolDocumentsContent
{
    /**
     * @return array{school_regulation_url: string|null, student_attendance_policy_url: string|null}
     */
    public static function forTenant(Tenant $tenant): array
    {
        $documents = $tenant->setting('documents') ?? [];

        return [
            'school_regulation_url' => self::urlFor($documents['school_regulation_path'] ?? null),
            'student_attendance_policy_url' => self::urlFor($documents['student_attendance_policy_path'] ?? null),
        ];
    }

    private static function urlFor(?string $path): ?string
    {
        return $path !== null ? Storage::disk('public')->url($path) : null;
    }
}
