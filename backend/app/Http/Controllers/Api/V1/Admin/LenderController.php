<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LenderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Lender;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Accounting > Borrowings > Lenders: the companies and people money is borrowed from. */
final class LenderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lender::class);

        $lenders = ApiQuery::for(Lender::query()->withCount('borrowings'), $request)
            ->searchable('name', 'contact_person', 'phone', 'email')
            ->filterable(['is_active', 'type'])
            ->sortable(['name', 'created_at'], default: 'name')
            ->paginate();

        return ApiResponse::success(LenderResource::collection($lenders));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Lender::class);

        $lender = Lender::query()->create($request->validate($this->rules(required: true)));

        return ApiResponse::created(new LenderResource($lender));
    }

    public function show(Lender $lender): JsonResponse
    {
        $this->authorize('view', $lender);

        return ApiResponse::success(new LenderResource($lender->loadCount('borrowings')));
    }

    public function update(Request $request, Lender $lender): JsonResponse
    {
        $this->authorize('update', $lender);

        $lender->update($request->validate($this->rules(required: false)));

        return ApiResponse::success(new LenderResource($lender->loadCount('borrowings')));
    }

    public function destroy(Lender $lender): JsonResponse
    {
        $this->authorize('delete', $lender);

        if ($lender->borrowings()->exists()) {
            return ApiResponse::error(__('This lender has loans and cannot be deleted. Deactivate it instead.'), 422);
        }

        $lender->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $required): array
    {
        $sometimes = $required ? [] : ['sometimes'];

        return [
            'name' => [...$sometimes, 'required', 'string', 'max:255'],
            'type' => [...$sometimes, 'required', Rule::in(Lender::TYPES)],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
