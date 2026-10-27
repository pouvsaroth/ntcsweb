<?php

declare(strict_types=1);

namespace App\Platform\Http\Resources;

use App\Models\Tenant;
use App\Platform\Models\School;
use App\Platform\Models\SchoolPlacement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A school as the back office shows it: the `tenants` row plus its bo_schools
 * row. The detail extras (domains, log, history) only when loaded.
 *
 * @mixin Tenant
 */
class SchoolResource extends JsonResource
{
    /** @return array<string, mixed> */
    private function placement(): array
    {
        $p = SchoolPlacement::of($this->resource);

        return [
            'mode' => $p->mode,
            'database' => $p->database,
            'host' => $p->host,
            'port' => $p->port,
            'username' => $p->username,
            'sslmode' => $p->sslmode,
        ];
    }

    public function toArray(Request $request): array
    {
        /** @var School|null $school */
        $school = $this->relationLoaded('backoffice') ? $this->getRelation('backoffice') : null;
        $owner = $school?->owner;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'slug' => $this->slug,
            'status' => $this->status,
            'subdomain' => $this->slug.'.'.config('tenancy.root_domain'),
            'database' => SchoolPlacement::of($this->resource)->database,
            // Where its data lives; a separate server's password never leaves the server.
            'placement' => $this->placement(),
            'domain_target' => config('platform.domain_target'),
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'default_currency' => $this->default_currency,
            // School, Company, Organization… (null = School).
            'organization_type_id' => $this->organization_type_id,
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'accepted' => $owner->status !== \App\Models\User::STATUS_INVITED,
            ] : null,
            'status_detail' => $school?->status_detail,
            // Created as a copy of this school's setup (SchoolSetupCopier), if any.
            'copy_setup_from' => $school?->copy_setup_from_tenant_id,
            'provisioning_attempts' => $school?->provisioning_attempts ?? 0,
            'activated_at' => $school?->activated_at?->toIso8601String(),
            'invite_sent_at' => $school?->invite_sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // Plan, end date and flag (stage 5) — set by SchoolController.
            'billing' => $this->resource->relationLoaded('billingSummary') ? $this->resource->getRelation('billingSummary') : null,
            // The Schools list health dot (stage 6a) — only for staff who may see health.
            'health' => $this->resource->relationLoaded('healthDot') ? $this->resource->getRelation('healthDot') : null,
            'domains' => $this->whenLoaded('domains', fn () => $this->domains->map(fn ($domain) => [
                'id' => $domain->id,
                'hostname' => $domain->hostname,
                'is_primary' => $domain->is_primary,
                'verified' => $domain->verified_at !== null || $domain->verification_token === null,
                'verified_at' => $domain->verified_at?->toIso8601String(),
                'txt_name' => $domain->verification_token ? '_ntcsweb-verify.'.$domain->hostname : null,
                'txt_value' => $domain->verification_token ? 'ntcsweb-verify='.$domain->verification_token : null,
            ])->values()->all()),
            'provisioning_log' => $this->when($school?->relationLoaded('provisioningLogs'), fn () => $school->provisioningLogs->map(fn ($log) => [
                'attempt' => $log->attempt,
                'step' => $log->step,
                'status' => $log->status,
                'message' => $log->message,
                'duration_ms' => $log->duration_ms,
                'at' => $log->created_at?->toIso8601String(),
            ])->values()->all()),
            'status_history' => $this->when($school?->relationLoaded('statusHistory'), fn () => $school->statusHistory->map(fn ($change) => [
                'from' => $change->from_status,
                'to' => $change->to_status,
                'reason' => $change->reason,
                'staff' => $change->staff?->name,
                'at' => $change->created_at?->toIso8601String(),
            ])->values()->all()),
        ];
    }
}
