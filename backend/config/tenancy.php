<?php

use App\Support\Tenancy\Resolvers\AuthenticatedUserTenantResolver;
use App\Support\Tenancy\Resolvers\DomainTenantResolver;
use App\Support\Tenancy\Resolvers\RequestTenantResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Central domains
    |--------------------------------------------------------------------------
    |
    | Hostnames that belong to the platform itself rather than to any school.
    | The super admin console lives here, and these hosts never resolve to a
    | tenant by hostname alone.
    |
    */

    'central_domains' => array_filter(array_map(
        'trim',
        explode(',', (string) env('TENANCY_CENTRAL_DOMAINS', 'localhost,127.0.0.1,admin.ntcsweb.com'))
    )),

    /*
    |--------------------------------------------------------------------------
    | Root domain
    |--------------------------------------------------------------------------
    |
    | Schools get a subdomain under this: newtech.ntcsweb.com. A hostname that
    | ends in the root domain is matched against tenants.slug, so a school is
    | reachable the moment it is created, with no tenant_domains row needed.
    | Schools may additionally attach custom domains (school.example.edu.kh).
    |
    */

    'root_domain' => env('TENANCY_ROOT_DOMAIN', 'ntcsweb.com'),

    /*
    |--------------------------------------------------------------------------
    | Auth guard consulted during resolution
    |--------------------------------------------------------------------------
    |
    | Tenant resolution runs before route-level auth middleware, so it asks this
    | guard directly rather than relying on $request->user() already being set.
    |
    */

    'auth_guard' => env('TENANCY_AUTH_GUARD', 'sanctum'),

    /*
    |--------------------------------------------------------------------------
    | Resolver chain
    |--------------------------------------------------------------------------
    |
    | Tried in order; the first one to return a tenant wins.
    |
    | Hostname is deliberately first: if a user of School A sends a request to
    | School B's domain we want the tenant to resolve to B so that the
    | EnsureTenantMatchesUser middleware can reject it, rather than silently
    | serving A's data from B's address.
    |
    */

    'resolvers' => [
        DomainTenantResolver::class,
        RequestTenantResolver::class,
        AuthenticatedUserTenantResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Explicit tenant in the request
    |--------------------------------------------------------------------------
    |
    | Allows `X-Tenant: <slug|id>` (or ?tenant=) to select a tenant, but only on
    | a central domain. This is what makes local development on
    | http://localhost:8080 usable before DNS exists.
    |
    | It is NOT a security hole: an authenticated non-super-admin is still
    | checked against their own tenant_id by EnsureTenantMatchesUser, so
    | claiming another tenant here produces a 403, never data.
    |
    */

    'allow_request_resolution' => (bool) env('TENANCY_ALLOW_REQUEST_RESOLUTION', true),

    'request_header' => 'X-Tenant',
    'request_parameter' => 'tenant',

    /*
    |--------------------------------------------------------------------------
    | Hostname lookup cache
    |--------------------------------------------------------------------------
    |
    | Hostname -> tenant runs on every single request, so it is cached. Keys are
    | keyed by hostname (a platform-level fact), not by tenant, and are flushed
    | whenever a Tenant or TenantDomain is written.
    |
    */

    'cache' => [
        'store' => env('TENANCY_CACHE_STORE', null), // null = default store
        'ttl' => (int) env('TENANCY_CACHE_TTL', 300),
        'prefix' => 'tenancy',
    ],

    /*
    |--------------------------------------------------------------------------
    | stancl/tenancy: database-per-tenant
    |--------------------------------------------------------------------------
    |
    | This app's own resolver chain above still decides *which* tenant a
    | request is for — these keys only configure the physical per-tenant
    | database that a table opts into once it's been converted (see
    | TenantProvisioningService and BelongsToTenant's docblock). We
    | deliberately don't use stancl's own domain-identification middleware,
    | tenant model, or the bootstrappers that touch cache/filesystem/queue —
    | just the database plumbing, kept as small as the package allows.
    |
    */

    'tenant_model' => App\Models\Tenant::class,

    'bootstrappers' => [
        Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
    ],

    'database' => [
        // The school's existing shared database — every model not yet
        // converted to a per-tenant one (the vast majority, for now) keeps
        // reading and writing here, completely unaffected by any of this.
        'central_connection' => env('DB_CONNECTION', 'pgsql'),

        'template_tenant_connection' => null,

        // A converted tenant's own database is named tenant<id>, e.g. tenant5.
        'prefix' => 'tenant',
        'suffix' => '',

        'managers' => [
            'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant migrations
    |--------------------------------------------------------------------------
    |
    | Only tables that have actually been converted live here — everything
    | else stays under the normal database/migrations, run once against the
    | shared database exactly as before.
    |
    */

    'migration_parameters' => [
        '--force' => true,
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

];
