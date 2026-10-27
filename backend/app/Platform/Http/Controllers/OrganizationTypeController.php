<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Models\OrganizationType;
use App\Platform\Support\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Organization types (School, Company, Organization, Ministry…): picked when a
 * customer is created, and their word replaces "school" on the admin screens.
 * A type customers still use is turned off instead of deleted.
 */
final class OrganizationTypeController extends Controller
{
    public function __construct(private readonly PlatformAudit $audit) {}

    public function index(): JsonResponse
    {
        $types = OrganizationType::query()->withCount('tenants')->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success($types->map(fn (OrganizationType $t) => $this->present($t))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $type = DB::connection((new OrganizationType)->getConnectionName())->transaction(function () use ($data) {
            $type = OrganizationType::query()->create($data + ['status' => OrganizationType::STATUS_ACTIVE]);
            $this->audit->record('organization_type.created', $type, new: $data);

            return $type;
        });

        return ApiResponse::created($this->present($type->loadCount('tenants')));
    }

    public function update(Request $request, OrganizationType $organizationType): JsonResponse
    {
        $data = $this->validated($request, $organizationType);
        $isSchool = $organizationType->code === OrganizationType::SCHOOL;
        abort_if($isSchool && isset($data['code']) && $data['code'] !== OrganizationType::SCHOOL, 409, __('The School type keeps its code.'));
        abort_if($isSchool && ($data['status'] ?? null) === OrganizationType::STATUS_INACTIVE, 409, __('The School type cannot be turned off.'));

        $old = $organizationType->only(array_keys($data));
        DB::connection($organizationType->getConnectionName())->transaction(function () use ($organizationType, $data, $old) {
            $organizationType->update($data);
            $this->audit->record('organization_type.updated', $organizationType, $old, $organizationType->only(array_keys($data)));
        });

        return ApiResponse::success($this->present($organizationType->loadCount('tenants')));
    }

    /** Deletes a type no customer uses; otherwise turns it off (its customers keep it). */
    public function destroy(OrganizationType $organizationType): JsonResponse
    {
        abort_if($organizationType->code === OrganizationType::SCHOOL, 409, __('The School type cannot be removed.'));

        $deleted = DB::connection($organizationType->getConnectionName())->transaction(function () use ($organizationType) {
            if ($organizationType->tenants()->exists()) {
                $organizationType->update(['status' => OrganizationType::STATUS_INACTIVE]);
                $this->audit->record('organization_type.deactivated', $organizationType, ['status' => OrganizationType::STATUS_ACTIVE], ['status' => OrganizationType::STATUS_INACTIVE]);

                return false;
            }
            $this->audit->record('organization_type.deleted', $organizationType, $organizationType->only(['code', 'words']));
            $organizationType->delete();

            return true;
        });

        return ApiResponse::success(['deleted' => $deleted]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?OrganizationType $type = null): array
    {
        $required = $type ? 'sometimes' : 'required';
        $data = $request->validate([
            'code' => [$required, 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique((new OrganizationType)->getConnectionName().'.bo_organization_types', 'code')->ignore($type?->getKey())],
            'words' => [$required, 'array'],
            'words.en' => ['required_with:words', 'string', 'max:60'],
            'words.en_plural' => ['nullable', 'string', 'max:60'],
            'words.km' => ['nullable', 'string', 'max:60'],
            'words.zh' => ['nullable', 'string', 'max:60'],
            'words.ja' => ['nullable', 'string', 'max:60'],
            'words.ko' => ['nullable', 'string', 'max:60'],
            'status' => ['sometimes', Rule::in([OrganizationType::STATUS_ACTIVE, OrganizationType::STATUS_INACTIVE])],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000'],
        ]);
        if (isset($data['words'])) {
            $words = [];
            foreach (OrganizationType::WORD_KEYS as $key) {
                $word = trim((string) ($data['words'][$key] ?? ''));
                if ($word !== '') {
                    $words[$key] = $word;
                }
            }
            // No plural given: the word + s.
            $words['en_plural'] ??= $words['en'].'s';
            $data['words'] = $words;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(OrganizationType $type): array
    {
        return [
            'id' => $type->id,
            'code' => $type->code,
            'words' => $type->words,
            'sort_order' => $type->sort_order,
            'status' => $type->status,
            'customers_count' => (int) ($type->tenants_count ?? 0),
        ];
    }
}
