<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveExpenseSupportRequest;
use App\Http\Resources\ExpenseSupportResource;
use App\Http\Responses\ApiResponse;
use App\Models\ExpenseSupport;
use App\Services\Accounting\ExpenseSupportReport;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Accounting > Expenses > Support: who gave money or food towards an Expense for (e.g. hospital visitors). */
final class ExpenseSupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ExpenseSupport::class);

        $query = ExpenseSupport::query()->with('expenseEntity:id,name')
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('support_date', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('support_date', '<=', $to));

        $supports = ApiQuery::for($query, $request)
            ->searchable('name', 'phone', 'items', 'note')
            ->filterable(['expense_entity_id', 'kind', 'relationship', 'currency'])
            ->sortable(['support_date', 'name', 'amount', 'created_at'], default: '-support_date')
            ->paginate();

        return ApiResponse::success(ExpenseSupportResource::collection($supports));
    }

    /** For each Expense for and currency: expenses, support money and expenses − support (ExpenseSupportReport). */
    public function summary(Request $request, ExpenseSupportReport $report): JsonResponse
    {
        return ApiResponse::success($report->build($this->reportFilters($request)));
    }

    public function excel(Request $request, ExpenseSupportReport $report): Response
    {
        return $this->download($report->excel($report->build($this->reportFilters($request))), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx');
    }

    public function pdf(Request $request, ExpenseSupportReport $report): Response
    {
        return $this->download($report->pdf($report->build($this->reportFilters($request))), 'application/pdf', 'pdf');
    }

    /** @return array<string, mixed> */
    private function reportFilters(Request $request): array
    {
        $this->authorize('viewAny', ExpenseSupport::class);

        return $request->validate([
            'expense_entity_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'kind' => ['nullable', 'in:money,food'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function download(string $bytes, string $type, string $extension): Response
    {
        return response($bytes, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="support-'.now()->format('Y-m-d').'.'.$extension.'"',
        ]);
    }

    public function store(SaveExpenseSupportRequest $request): JsonResponse
    {
        $support = ExpenseSupport::query()->create([...$this->clean($request->validated()), 'created_by' => $request->user()->getKey()]);

        return ApiResponse::created(new ExpenseSupportResource($support->load('expenseEntity:id,name')));
    }

    public function show(ExpenseSupport $expenseSupport): JsonResponse
    {
        $this->authorize('view', $expenseSupport);

        return ApiResponse::success(new ExpenseSupportResource($expenseSupport->load('expenseEntity:id,name')));
    }

    public function update(SaveExpenseSupportRequest $request, ExpenseSupport $expenseSupport): JsonResponse
    {
        $expenseSupport->update($this->clean($request->validated()));

        return ApiResponse::success(new ExpenseSupportResource($expenseSupport->load('expenseEntity:id,name')));
    }

    public function destroy(ExpenseSupport $expenseSupport): JsonResponse
    {
        $this->authorize('delete', $expenseSupport);
        $expenseSupport->delete();

        return ApiResponse::noContent();
    }

    /**
     * No currency without an amount (food with no estimate).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        if (! isset($data['amount']) || $data['amount'] === '') {
            $data['amount'] = null;
            $data['currency'] = null;
        }

        return $data;
    }
}
