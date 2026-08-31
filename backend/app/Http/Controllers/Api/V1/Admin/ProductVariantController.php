<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreProductVariantRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProductVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;

/**
 * Nested under a Product — a variant never exists on its own (see
 * ProductVariant's docblock: optional, only for products that need sizing).
 * Authorization piggybacks on the parent Product's own update permission —
 * there is no separate `product-variants.*` permission.
 */
final class ProductVariantController extends Controller
{
    public function store(StoreProductVariantRequest $request, Product $product): JsonResponse
    {
        $variant = $product->variants()->create($request->validated());

        return ApiResponse::created(new ProductVariantResource($variant));
    }

    public function update(UpdateProductVariantRequest $request, ProductVariant $variant): JsonResponse
    {
        $variant->update($request->validated());

        return ApiResponse::success(new ProductVariantResource($variant));
    }

    public function destroy(ProductVariant $variant): JsonResponse
    {
        $this->authorize('update', $variant->product);

        $variant->delete();

        return ApiResponse::noContent();
    }
}
