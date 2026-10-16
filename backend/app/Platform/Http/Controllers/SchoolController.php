<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Platform\Http\Resources\SchoolResource;
use App\Platform\Jobs\ProvisionSchool;
use App\Platform\Models\School;
use App\Platform\Services\SchoolDatabase;
use App\Platform\Services\SchoolOwners;
use App\Platform\Services\SchoolStatus;
use App\Platform\Support\PlatformAudit;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        ]);

        $tenant = DB::connection((new Tenant)->getConnectionName())->transaction(function () use ($data, $request) {
            $tenant = new Tenant([
                'name' => $data['name'],
                'name_en' => $data['name_en'] ?? null,
                'slug' => $data['slug'],
                'timezone' => $data['timezone'] ?? 'Asia/Phnom_Penh',
                'locale' => $data['locale'] ?? 'km',
                'default_currency' => $data['default_currency'] ?? Tenant::CURRENCY_USD,
            ]);
            $tenant->forceFill(['status' => Tenant::STATUS_PROVISIONING])->save();

            School::for($tenant)->forceFill(['created_by' => $request->user('staff')->getKey()])->save();
            $this->owners->ensureAccount($tenant, $data['owner_name'], $data['owner_email']);

            $this->audit->record('school.created', $tenant, new: [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'owner_email' => $data['owner_email'],
            ], tenantId: $tenant->getKey());

            return $tenant;
        });

        ProvisionSchool::dispatch($tenant->getKey())->afterCommit();

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
