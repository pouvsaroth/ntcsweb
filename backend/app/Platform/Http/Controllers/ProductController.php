<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Billing\Modules;
use App\Platform\Models\Plan;
use App\Platform\Models\Product;
use App\Platform\Support\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Billing → Systems (docs/backoffice/stage-9-products.md, 9a): what the
 * platform sells — a name, logo and the modules each system is built from.
 * A system with plans is deactivated instead of deleted.
 */
final class ProductController extends Controller
{
    public function __construct(private readonly PlatformAudit $audit) {}

    public function index(): JsonResponse
    {
        $products = Product::query()->withCount('plans')->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success([
            'products' => $products->map(fn (Product $p) => $this->present($p))->values(),
            'modules' => Modules::list(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $product = DB::connection((new Product)->getConnectionName())->transaction(function () use ($data) {
            $product = Product::query()->create($data + ['status' => Product::STATUS_ACTIVE]);
            $this->audit->record('billing.product_created', $product, new: $data);

            return $product;
        });

        return ApiResponse::created($this->present($product->loadCount('plans')));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $this->validated($request, $product);

        // A module its plans still use cannot be taken out of the system.
        if (isset($data['modules'])) {
            $used = $product->plans()->pluck('modules')->flatten()->unique()->all();
            $missing = array_values(array_diff($used, $data['modules']));
            if ($missing !== []) {
                throw ValidationException::withMessages(['modules' => __('Its plans still use: :modules. Remove them from those plans first.', ['modules' => implode(', ', $missing)])]);
            }
        }
        abort_if($product->code === Product::SCHOOL && isset($data['code']) && $data['code'] !== Product::SCHOOL, 409, __('The school system keeps its code.'));

        $old = $product->only(array_keys($data));
        DB::connection($product->getConnectionName())->transaction(function () use ($product, $data, $old) {
            $product->update($data);
            $this->audit->record('billing.product_updated', $product, $old, $product->only(array_keys($data)));
        });

        return ApiResponse::success($this->present($product->loadCount('plans')));
    }

    /** Deletes a system with no plans; otherwise deactivates it (its plans and customers stay). */
    public function destroy(Product $product): JsonResponse
    {
        abort_if($product->code === Product::SCHOOL, 409, __('The school system cannot be removed.'));

        $deleted = DB::connection($product->getConnectionName())->transaction(function () use ($product) {
            if ($product->plans()->exists()) {
                $product->update(['status' => Product::STATUS_INACTIVE]);
                $this->audit->record('billing.product_deactivated', $product, ['status' => Product::STATUS_ACTIVE], ['status' => Product::STATUS_INACTIVE]);

                return false;
            }
            $this->audit->record('billing.product_deleted', $product, $product->only(['code', 'name']));
            if ($product->logo_path) {
                Storage::disk('public')->delete($product->logo_path);
            }
            $product->delete();

            return true;
        });

        return ApiResponse::success(['deleted' => $deleted]);
    }

    public function uploadLogo(Request $request, Product $product): JsonResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048']]);
        $old = $product->logo_path;
        $path = $request->file('logo')->store('platform/products', 'public');

        $product->update(['logo_path' => $path]);
        $this->audit->record('billing.product_logo_changed', $product, ['logo_path' => $old], ['logo_path' => $path]);
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return ApiResponse::success($this->present($product->loadCount('plans')));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Product $product = null): array
    {
        $required = $product ? 'sometimes' : 'required';
        $data = $request->validate([
            'code' => [$required, 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique((new Product)->getConnectionName().'.bo_products', 'code')->ignore($product?->getKey())],
            'name' => [$required, 'string', 'max:100'],
            'name_km' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'description_km' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'modules' => [$required, 'array', 'min:1'],
            'modules.*' => ['string', 'distinct', Rule::in(Modules::codes())],
            'status' => ['sometimes', Rule::in([Product::STATUS_ACTIVE, Product::STATUS_INACTIVE])],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000'],
        ]);
        if (isset($data['modules'])) {
            $data['modules'] = array_values(array_intersect(Modules::codes(), $data['modules']));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Product $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'name_km' => $p->name_km,
            'description' => $p->description,
            'description_km' => $p->description_km,
            'logo_url' => $p->logoUrl(),
            'modules' => $p->modules ?? [],
            'status' => $p->status,
            'sort_order' => $p->sort_order,
            'is_school' => $p->code === Product::SCHOOL,
            'plans_count' => (int) ($p->plans_count ?? 0),
        ];
    }
}
