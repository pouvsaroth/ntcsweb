<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Platform\Support\DnsTxtLookup;
use App\Platform\Support\PlatformAudit;
use App\Support\Tenancy\TenantHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A school's custom domains. Added unverified with a token; the domain
 * resolves only once `_ntcsweb-verify.<host>` carries that token as a TXT
 * record (DomainTenantResolver). `<slug>.<root>` needs no row at all.
 * TLS and the nginx side stay a server step.
 */
final class SchoolDomainController extends Controller
{
    public function __construct(
        private readonly TenantHost $host,
        private readonly DnsTxtLookup $dns,
        private readonly PlatformAudit $audit,
    ) {}

    public function store(Request $request, Tenant $school): JsonResponse
    {
        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:253', 'regex:/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i'],
        ]);

        $hostname = $this->host->normalise($data['hostname']) ?? Str::lower($data['hostname']);
        $root = (string) config('tenancy.root_domain');

        $refuse = match (true) {
            $this->host->isCentral($hostname) => __('That is one of the platform\'s own addresses.'),
            $hostname === $root || str_ends_with($hostname, '.'.$root) => __('Subdomains of :root are automatic — no need to add one.', ['root' => $root]),
            TenantDomain::query()->where('hostname', $hostname)->exists() => __('That domain is already attached to a school.'),
            default => null,
        };
        if ($refuse !== null) {
            throw ValidationException::withMessages(['hostname' => $refuse]);
        }

        $domain = new TenantDomain(['tenant_id' => $school->getKey(), 'hostname' => $hostname, 'type' => TenantDomain::TYPE_CUSTOM, 'is_primary' => false]);
        $domain->forceFill(['verification_token' => Str::lower(Str::random(32))])->save();

        $this->audit->record('school.domain_added', $school, new: ['hostname' => $hostname], tenantId: $school->getKey());

        return ApiResponse::created(['id' => $domain->id, 'hostname' => $domain->hostname]);
    }

    /** "Check now": look for the TXT record; found → verified, and the domain starts resolving. */
    public function verify(Tenant $school, TenantDomain $domain): JsonResponse
    {
        $this->ensureBelongs($school, $domain);

        if ($domain->verified_at !== null || $domain->verification_token === null) {
            return ApiResponse::success(['verified' => true]);
        }

        $expected = 'ntcsweb-verify='.$domain->verification_token;
        $found = in_array($expected, $this->dns->txt('_ntcsweb-verify.'.$domain->hostname), true);

        if ($found) {
            $domain->forceFill(['verified_at' => now()])->save();
            $this->audit->record('school.domain_verified', $school, new: ['hostname' => $domain->hostname], tenantId: $school->getKey());
        }

        return ApiResponse::success(['verified' => $found]);
    }

    public function primary(Tenant $school, TenantDomain $domain): JsonResponse
    {
        $this->ensureBelongs($school, $domain);

        if ($domain->verified_at === null && $domain->verification_token !== null) {
            throw ValidationException::withMessages(['domain' => __('Verify the domain before making it primary.')]);
        }

        DB::connection($domain->getConnectionName())->transaction(function () use ($school, $domain) {
            $school->domains()->where('id', '!=', $domain->id)->where('is_primary', true)->get()
                ->each(fn (TenantDomain $other) => $other->forceFill(['is_primary' => false])->save());
            $domain->forceFill(['is_primary' => true])->save();
            $this->audit->record('school.domain_primary', $school, new: ['hostname' => $domain->hostname], tenantId: $school->getKey());
        });

        return ApiResponse::success(null);
    }

    public function destroy(Tenant $school, TenantDomain $domain): JsonResponse
    {
        $this->ensureBelongs($school, $domain);

        $domain->delete();
        $this->audit->record('school.domain_removed', $school, ['hostname' => $domain->hostname], tenantId: $school->getKey());

        return ApiResponse::noContent();
    }

    private function ensureBelongs(Tenant $school, TenantDomain $domain): void
    {
        abort_unless((int) $domain->tenant_id === (int) $school->getKey(), 404);
    }
}
