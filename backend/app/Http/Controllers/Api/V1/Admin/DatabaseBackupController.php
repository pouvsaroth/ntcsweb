<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Lets a platform Super Admin download a pg_dump of the central database or
 * any active school's own database — deliberately no host/username/password
 * form field anywhere: every database this ever touches is one this server
 * already holds real credentials for (config/database.php), so accepting
 * connection details from the request would turn this into a way to make
 * the server dump an arbitrary third-party Postgres server instead.
 *
 * Restricted to `isSuperAdmin()` directly rather than a permission, matching
 * TenantPolicy's own reasoning: every permission check here would just be
 * bypassed by Gate::before for a super admin anyway, and this must never be
 * grantable to anyone else — a tenant's own database is every other tenant's
 * private data boundary.
 */
final class DatabaseBackupController extends Controller
{
    /** @return array{host: string, port: int|string, username: string, password: string} */
    private function connectionCredentials(): array
    {
        $connection = config('database.connections.pgsql');

        return [
            'host' => $connection['host'],
            'port' => $connection['port'],
            'username' => $connection['username'],
            'password' => $connection['password'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $databases = collect([[
            'type' => 'central',
            'tenant_id' => null,
            'label' => 'Central (Platform)',
            'database' => config('database.connections.pgsql.database'),
        ]])->concat(
            Tenant::query()->active()->orderBy('name')->get()->map(fn (Tenant $tenant) => [
                'type' => 'tenant',
                'tenant_id' => $tenant->id,
                'label' => $tenant->name,
                'database' => $tenant->database()->getName(),
            ])
        );

        return ApiResponse::success($databases->values());
    }

    public function download(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $data = $request->validate([
            'type' => ['required', Rule::in(['central', 'tenant'])],
            'tenant_id' => [
                'required_if:type,tenant',
                'prohibited_if:type,central',
                Rule::exists('tenants', 'id')->where(fn ($query) => $query->where('status', Tenant::STATUS_ACTIVE)),
            ],
        ]);

        if ($data['type'] === 'central') {
            $databaseName = config('database.connections.pgsql.database');
            $slug = 'central';
        } else {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->active()->findOrFail($data['tenant_id']);
            $databaseName = $tenant->database()->getName();
            $slug = $tenant->slug;
        }

        $credentials = $this->connectionCredentials();
        $tempPath = tempnam(sys_get_temp_dir(), 'dbbackup_');
        $handle = fopen($tempPath, 'w');

        $result = Process::timeout(600)
            ->env(['PGPASSWORD' => $credentials['password']])
            ->run([
                'pg_dump',
                '--host='.$credentials['host'],
                '--port='.$credentials['port'],
                '--username='.$credentials['username'],
                '--no-password',
                '--format=plain',
                $databaseName,
            ], function (string $type, string $bytes) use ($handle) {
                if ($type === SymfonyProcess::OUT) {
                    fwrite($handle, $bytes);
                }
            });

        fclose($handle);

        if (! $result->successful()) {
            @unlink($tempPath);
            abort(500, 'Backup failed: '.$result->errorOutput());
        }

        $filename = sprintf('%s-%s.sql', $slug, now()->format('Y-m-d_His'));

        return response()->download($tempPath, $filename, ['Content-Type' => 'application/sql'])->deleteFileAfterSend();
    }
}
