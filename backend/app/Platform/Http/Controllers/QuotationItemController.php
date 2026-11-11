<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Models\QuotationItem;
use App\Platform\Support\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Billing > Quotation items: the menus > submenus > tabs a quotation picks
 * from, with default USD / KHR prices. Hidden ones stay on old quotations
 * (lines keep their own copy of the names and prices).
 */
final class QuotationItemController extends Controller
{
    public function __construct(private readonly PlatformAudit $audit) {}

    /** The whole tree, menus first, hidden items included (flagged). */
    public function index(): JsonResponse
    {
        $all = QuotationItem::query()->orderBy('sort_order')->orderBy('id')->get();
        $byParent = $all->groupBy(fn (QuotationItem $i) => $i->parent_id ?? 0);

        $build = function (int $parentId) use (&$build, $byParent): array {
            return ($byParent[$parentId] ?? collect())
                ->map(fn (QuotationItem $i) => $this->present($i) + ['children' => $build($i->id)])
                ->values()
                ->all();
        };

        return ApiResponse::success($build(0));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $parent = isset($data['parent_id']) ? QuotationItem::query()->findOrFail($data['parent_id']) : null;
        $level = match ($parent?->level) {
            null => 'menu',
            'menu' => 'submenu',
            'submenu' => 'tab',
            default => throw ValidationException::withMessages(['parent_id' => __('A tab can\'t have items under it.')]),
        };
        $sort = (int) QuotationItem::query()->where('parent_id', $parent?->id)->max('sort_order') + 1;

        $item = QuotationItem::query()->create($data + ['level' => $level, 'sort_order' => $data['sort_order'] ?? $sort]);
        $this->audit->record('billing.quotation_item_created', $item, new: $item->only(['level', 'name', 'price_usd', 'price_khr']));

        return ApiResponse::created($this->present($item) + ['children' => []]);
    }

    public function update(Request $request, QuotationItem $item): JsonResponse
    {
        $data = $this->validated($request, $item);
        unset($data['parent_id']);
        $old = $item->only(array_keys($data));
        $item->update($data);
        $this->audit->record('billing.quotation_item_updated', $item, $old, $item->only(array_keys($data)));

        return ApiResponse::success($this->present($item));
    }

    /** Deletes the item and everything under it; quotations keep their copies. */
    public function destroy(QuotationItem $item): JsonResponse
    {
        $this->audit->record('billing.quotation_item_deleted', $item, $item->only(['level', 'code', 'name']));
        $item->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?QuotationItem $item = null): array
    {
        $required = $item ? 'sometimes' : 'required';

        return $request->validate([
            'parent_id' => ['nullable', 'integer', Rule::exists((new QuotationItem)->getConnectionName().'.bo_quotation_items', 'id')],
            'code' => ['nullable', 'string', 'max:30'],
            'name' => [$required, 'string', 'max:150'],
            'name_km' => ['nullable', 'string', 'max:150'],
            'path' => ['nullable', 'string', 'max:150'],
            'price_usd' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'price_khr' => ['sometimes', 'numeric', 'min:0', 'max:99999999999999'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(QuotationItem $i): array
    {
        return [
            'id' => $i->id,
            'parent_id' => $i->parent_id,
            'level' => $i->level,
            'code' => $i->code,
            'name' => $i->name,
            'name_km' => $i->name_km,
            'path' => $i->path,
            'price_usd' => (float) $i->price_usd,
            'price_khr' => (float) $i->price_khr,
            'active' => $i->active,
            'sort_order' => $i->sort_order,
        ];
    }
}
