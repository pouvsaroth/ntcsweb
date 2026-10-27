<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Models\Tenant;
use App\Platform\Billing\Subscriptions;
use App\Platform\Jobs\ProvisionSchool;
use App\Platform\Models\PlatformStaff;
use App\Platform\Models\School;
use App\Platform\Models\SchoolPlacement;
use App\Platform\Support\PlatformAudit;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * A new school, then its background setup (ProvisionSchool). One way in for
 * back office → Schools → Create (staff) and the website's self-service
 * sign-up (no staff, docs/backoffice/stage-8-signup.md).
 */
final class SchoolCreator
{
    public function __construct(
        private readonly SchoolOwners $owners,
        private readonly SchoolDatabase $database,
        private readonly PlatformAudit $audit,
        private readonly Subscriptions $subscriptions,
    ) {}

    /**
     * @param  array<string, mixed>  $data  name, name_en, slug, owner_name, owner_email; optional timezone, locale,
     *                                      default_currency, email, phone, server (for a separate placement),
     *                                      copy_setup_from (tenant id whose setup is copied), organization_type_id
     * @param  string|null  $ownerPassword  A password the owner chose themselves (sign-up). Null: they are invited by email.
     * @param  (Closure(Tenant): mixed)|null  $subscribe  Puts the school on a plan; null = the usual trial.
     */
    public function create(array $data, string $mode, ?PlatformStaff $actor, ?string $ownerPassword = null, ?Closure $subscribe = null, string $auditAction = 'school.created'): Tenant
    {
        $tenant = DB::connection((new Tenant)->getConnectionName())->transaction(function () use ($data, $mode, $actor, $ownerPassword, $subscribe, $auditAction) {
            $tenant = new Tenant([
                'name' => $data['name'],
                'name_en' => $data['name_en'] ?? null,
                'slug' => $data['slug'],
                'timezone' => $data['timezone'] ?? 'Asia/Phnom_Penh',
                'locale' => $data['locale'] ?? 'km',
                'default_currency' => $data['default_currency'] ?? Tenant::CURRENCY_USD,
                'organization_type_id' => $data['organization_type_id'] ?? null,
            ]);
            foreach (['email', 'phone'] as $field) {
                if (! empty($data[$field])) {
                    $tenant->{$field} = $data[$field];
                }
            }
            $tenant->forceFill(['status' => Tenant::STATUS_PROVISIONING])->save();

            School::for($tenant)->forceFill([
                'created_by' => $actor?->getKey(),
                'copy_setup_from_tenant_id' => $data['copy_setup_from'] ?? null,
                'copy_setup_parts' => ! empty($data['copy_setup_from']) && ! empty($data['copy_setup_parts']) ? SchoolSetupCopier::withNeeds($data['copy_setup_parts']) : null,
            ])->save();

            SchoolPlacement::query()->create(match ($mode) {
                SchoolPlacement::SHARED => ['tenant_id' => $tenant->id, 'mode' => $mode, 'database' => config('tenancy.database.shared_database')],
                SchoolPlacement::DEDICATED => ['tenant_id' => $tenant->id, 'mode' => $mode, 'database' => $this->database->nameFor($tenant->slug)],
                SchoolPlacement::SEPARATE => [
                    'tenant_id' => $tenant->id, 'mode' => $mode,
                    'database' => $data['server']['database'], 'host' => $data['server']['host'], 'port' => (int) $data['server']['port'],
                    'username' => $data['server']['username'], 'password' => $data['server']['password'],
                    'sslmode' => $data['server']['sslmode'] ?? 'prefer',
                ],
            });
            $owner = $this->owners->ensureAccount($tenant, $data['owner_name'], $data['owner_email']);
            if ($ownerPassword !== null) {
                // Chosen by the owner on the sign-up form (already hashed): no invite needed.
                $owner->forceFill(['password' => $ownerPassword, 'status' => \App\Models\User::STATUS_ACTIVE, 'phone' => $data['phone'] ?? $owner->phone])->save();
            }

            // Every new school starts on a trial (docs/backoffice/stage-5-billing.md), unless a plan is bought.
            $subscribe !== null ? $subscribe($tenant) : $this->subscriptions->startTrial($tenant, $actor);

            $this->audit->record($auditAction, $tenant, new: [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'owner_email' => $data['owner_email'],
                'placement' => $mode,
                'copy_setup_from' => $data['copy_setup_from'] ?? null,
                'copy_setup_parts' => $data['copy_setup_parts'] ?? null,
                'organization_type_id' => $data['organization_type_id'] ?? null,
            ], tenantId: $tenant->getKey(), actor: $actor);

            return $tenant;
        });

        ProvisionSchool::dispatch($tenant->getKey())->afterCommit();

        return $tenant;
    }
}
