<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Platform\Billing\Subscriptions;
use App\Platform\Http\Resources\SchoolResource;
use App\Platform\Jobs\ProvisionSchool;
use App\Platform\Models\School;
use App\Platform\Models\SchoolPlacement;
use App\Platform\Services\SchoolCreator;
use App\Platform\Services\SchoolDatabase;
use App\Platform\Services\SchoolOwners;
use App\Platform\Services\SchoolStatus;
use App\Platform\Services\SeparateServerProbe;
use App\Platform\Support\PlatformAudit;
use App\Platform\Support\SchoolHealth;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Schools: list, open, create (→ background provisioning), retry, edit the
 * profile, and move status. See docs/backoffice/stage-2-schools.md.
 */
final class SchoolController extends Controller
{
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/';

    public function __construct(
        private readonly SchoolStatus $status,
        private readonly SchoolOwners $owners,
        private readonly SchoolDatabase $database,
        private readonly PlatformAudit $audit,
        private readonly SeparateServerProbe $probe,
        private readonly Subscriptions $subscriptions,
        private readonly SchoolHealth $health,
        private readonly SchoolCreator $creator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Tenant::query()
            // Archived schools only when asked for.
            ->when($request->input('filter.status') === null, fn ($q) => $q->where('status', '!=', Tenant::STATUS_ARCHIVED));

        $tenants = ApiQuery::for($query, $request)
            ->searchable('name', 'name_en', 'slug')
            ->filterable(['status'])
            ->sortable(['name', 'slug', 'created_at'], default: '-created_at')
            ->paginate();

        $this->attachSchools($tenants->getCollection());
        $billing = $this->subscriptions->summaries($tenants->getCollection()->modelKeys());
        $tenants->getCollection()->each(fn (Tenant $t) => $t->setRelation('billingSummary', $billing[$t->getKey()] ?? null));

        // The health dot (stage 6a): central tables only, for staff who may see health.
        if ($request->user('staff')->hasPermission('platform.support.read')) {
            $dots = $this->health->dots($tenants->getCollection(), $billing);
            $tenants->getCollection()->each(fn (Tenant $t) => $t->setRelation('healthDot', $dots[$t->getKey()] ?? null));
        }

        return ApiResponse::success(SchoolResource::collection($tenants));
    }

    public function show(Tenant $school): JsonResponse
    {
        return ApiResponse::success(new SchoolResource($this->detailed($school)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'slug' => ['required', 'string', 'regex:'.self::SLUG_PATTERN, Rule::notIn(config('platform.reserved_slugs', [])), $this->slugIsFree(...)],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
            'timezone' => ['nullable', 'timezone'],
            'locale' => ['nullable', Rule::in(['en', 'km', 'zh', 'ko', 'ja'])],
            'default_currency' => ['nullable', Rule::in([Tenant::CURRENCY_USD, Tenant::CURRENCY_KHR])],
            // Where its data lives (docs/backoffice/stage-4-database-placement.md).
            'placement' => ['nullable', Rule::in(SchoolPlacement::MODES)],
            'server' => ['exclude_unless:placement,separate', 'required', 'array'],
            'server.host' => ['exclude_unless:placement,separate', 'required', 'string', 'max:255'],
            'server.port' => ['exclude_unless:placement,separate', 'required', 'integer', 'between:1,65535'],
            'server.database' => ['exclude_unless:placement,separate', 'required', 'string', 'max:63', 'regex:/^[A-Za-z_][A-Za-z0-9_$-]*$/'],
            'server.username' => ['exclude_unless:placement,separate', 'required', 'string', 'max:63'],
            'server.password' => ['exclude_unless:placement,separate', 'required', 'string', 'max:255'],
            'server.sslmode' => ['exclude_unless:placement,separate', 'nullable', Rule::in(SeparateServerProbe::SSL_MODES)],
        ]);

        $mode = $data['placement'] ?? config('platform.default_placement', SchoolPlacement::SHARED);

        // A separate server must answer before anything is created for it.
        if ($mode === SchoolPlacement::SEPARATE) {
            $probe = $this->probe->probe($data['server']);
            if (! $probe['ok']) {
                throw ValidationException::withMessages(['server' => $probe['message']]);
            }
        }

        $tenant = $this->creator->create($data, $mode, $request->user('staff'));

        return ApiResponse::created(new SchoolResource($this->detailed($tenant->fresh())));
    }

    public function update(Request $request, Tenant $school): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'timezone' => ['sometimes', 'timezone'],
            'locale' => ['sometimes', Rule::in(['en', 'km', 'zh', 'ko', 'ja'])],
            'default_currency' => ['sometimes', Rule::in([Tenant::CURRENCY_USD, Tenant::CURRENCY_KHR])],
            // A slug is the database name and the subdomain: never editable.
            'slug' => ['prohibited'],
        ]);

        $old = $school->only(array_keys($data));
        $school->fill($data)->save();
        $this->audit->record('school.updated', $school, $old, $school->only(array_keys($data)), tenantId: $school->getKey());

        return ApiResponse::success(new SchoolResource($this->detailed($school)));
    }

    /** "Test connection" for a separate server: nothing is saved. */
    public function testConnection(Request $request): JsonResponse
    {
        $server = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:63'],
            'username' => ['required', 'string', 'max:63'],
            'password' => ['required', 'string', 'max:255'],
            'sslmode' => ['nullable', Rule::in(SeparateServerProbe::SSL_MODES)],
        ]);

        return ApiResponse::success($this->probe->probe($server));
    }

    /** A failed school: back to provisioning, and the job runs again from the top (every step is idempotent). */
    public function retry(Request $request, Tenant $school): JsonResponse
    {
        $this->status->move($school, Tenant::STATUS_PROVISIONING, __('Retry'), $request->user('staff'));
        ProvisionSchool::dispatch($school->getKey());

        return ApiResponse::success(new SchoolResource($this->detailed($school->fresh())));
    }

    public function suspend(Request $request, Tenant $school): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->status->move($school, Tenant::STATUS_SUSPENDED, $data['reason'], $request->user('staff'));

        return ApiResponse::success(new SchoolResource($this->detailed($school)));
    }

    public function reactivate(Request $request, Tenant $school): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->status->move($school, Tenant::STATUS_ACTIVE, $data['reason'] ?? null, $request->user('staff'));

        return ApiResponse::success(new SchoolResource($this->detailed($school)));
    }

    public function archive(Request $request, Tenant $school): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->status->move($school, Tenant::STATUS_ARCHIVED, $data['reason'], $request->user('staff'));

        return ApiResponse::success(new SchoolResource($this->detailed($school)));
    }

    /** Taken by any school (archived and soft-deleted included), or by a database already on the server. */
    private function slugIsFree(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (Tenant::query()->withTrashed()->where('slug', $value)->exists()) {
            $fail(__('Another school already uses this slug.'));

            return;
        }

        if ($this->database->exists($this->database->nameFor($value))) {
            $fail(__('A database named :name already exists on the server.', ['name' => $this->database->nameFor($value)]));
        }
    }

    private function detailed(Tenant $tenant): Tenant
    {
        $school = School::for($tenant)->load([
            'owner',
            'statusHistory' => fn ($q) => $q->with('staff:id,name')->latest('id'),
            // The latest attempt's steps — earlier attempts are history.
            'provisioningLogs' => fn ($q) => $q->where('attempt', fn ($sub) => $sub
                ->selectRaw('max(attempt)')
                ->from('bo_school_provisioning_logs')
                ->where('tenant_id', $tenant->getKey()))->orderBy('id'),
        ]);

        $tenant->setRelation('billingSummary', $this->subscriptions->summary($tenant));

        return $tenant->load('domains')->setRelation('backoffice', $school);
    }

    /** @param Collection<int, Tenant> $tenants */
    private function attachSchools(Collection $tenants): void
    {
        $schools = School::query()->with('owner')->whereIn('tenant_id', $tenants->modelKeys())->get()->keyBy('tenant_id');

        foreach ($tenants as $tenant) {
            $tenant->setRelation('backoffice', $schools[$tenant->getKey()] ?? School::for($tenant)->load('owner'));
        }
    }
}
