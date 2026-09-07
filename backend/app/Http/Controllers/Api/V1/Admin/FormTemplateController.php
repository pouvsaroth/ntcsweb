<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreFormTemplateRequest;
use App\Http\Requests\Api\V1\Admin\UpdateFormTemplateRequest;
use App\Http\Resources\FormTemplateResource;
use App\Http\Responses\ApiResponse;
use App\Models\FormTemplate;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * index() is deliberately unguarded by a permission — every authenticated
 * user browses this catalog to submit a request from the Forms page (see
 * FormTemplatePolicy's docblock). Only create/update/delete require
 * form-templates.manage.
 */
final class FormTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $templates = ApiQuery::for(FormTemplate::query()->with('category'), $request)
            ->filterable(['form_category_id'])
            ->sortable(['order', 'name', 'created_at'], default: 'order')
            ->paginate();

        return ApiResponse::success(FormTemplateResource::collection($templates));
    }

    public function store(StoreFormTemplateRequest $request): JsonResponse
    {
        $template = FormTemplate::query()->create($request->validated());

        return ApiResponse::created(new FormTemplateResource($template->load('category')));
    }

    public function show(FormTemplate $formTemplate): JsonResponse
    {
        return ApiResponse::success(new FormTemplateResource($formTemplate->load('category')));
    }

    public function update(UpdateFormTemplateRequest $request, FormTemplate $formTemplate): JsonResponse
    {
        $formTemplate->update($request->validated());

        return ApiResponse::success(new FormTemplateResource($formTemplate->load('category')));
    }

    public function destroy(FormTemplate $formTemplate): JsonResponse
    {
        $this->authorize('delete', $formTemplate);

        $formTemplate->delete();

        return ApiResponse::noContent();
    }
}
