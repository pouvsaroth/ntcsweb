<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\CoursePackage;
use App\Models\Product;
use App\Support\Billing\ProductType;
use Illuminate\Support\Facades\DB;

/**
 * A Course Package auto-owns a billable Product row (see the migration's
 * docblock for why) — this is the one place that link is created and kept
 * in sync. Every InvoiceItem still points at a normal Product, so
 * RevenueAccountResolver/FinancialTransactionService need zero changes to
 * post revenue for a package sale.
 *
 * Changing a package's price here only ever affects the *live catalog*
 * price (this row and its Product's `price` column) — an already-issued
 * InvoiceItem snapshots its own `unit_price` at creation time
 * (InvoiceService::addItem()) and never re-reads either of these, so
 * history is never rewritten.
 */
final class CoursePackageService
{
    /**
     * @param  array{code:string, name:string, academic_program_id:int, description?:string|null, price:float, duration?:string|null, is_active?:bool, book_ids:list<int>}  $data
     */
    public function create(array $data): CoursePackage
    {
        return DB::transaction(function () use ($data) {
            $bookIds = $data['book_ids'];
            unset($data['book_ids']);

            $product = Product::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => ProductType::COURSE_FEE,
                'price' => $data['price'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $package = CoursePackage::query()->create([
                ...$data,
                'product_id' => $product->getKey(),
            ]);

            $package->books()->sync($this->booksWithOrder($bookIds));

            return $package->load(['product', 'books']);
        });
    }

    /**
     * @param  array{code?:string, name?:string, academic_program_id?:int, description?:string|null, price?:float, duration?:string|null, is_active?:bool, book_ids?:list<int>}  $data
     */
    public function update(CoursePackage $package, array $data): CoursePackage
    {
        return DB::transaction(function () use ($package, $data) {
            $bookIds = $data['book_ids'] ?? null;
            unset($data['book_ids']);

            $package->update($data);

            if ($package->product !== null) {
                $package->product->update([
                    'name' => $package->name,
                    'description' => $package->description,
                    'price' => $package->price,
                    'is_active' => $package->is_active,
                ]);
            }

            if ($bookIds !== null) {
                $package->books()->sync($this->booksWithOrder($bookIds));
            }

            return $package->load(['product', 'books']);
        });
    }

    /**
     * @param  list<int>  $bookIds
     * @return array<int, array{sort_order: int}>
     */
    private function booksWithOrder(array $bookIds): array
    {
        $synced = [];
        foreach (array_values($bookIds) as $index => $bookId) {
            $synced[$bookId] = ['sort_order' => $index];
        }

        return $synced;
    }
}
