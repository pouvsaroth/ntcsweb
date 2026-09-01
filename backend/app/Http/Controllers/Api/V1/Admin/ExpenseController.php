<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ApproveExpenseRequest;
use App\Http\Requests\Api\V1\Admin\CancelExpenseRequest;
use App\Http\Requests\Api\V1\Admin\PayExpenseRequest;
use App\Http\Requests\Api\V1\Admin\RejectExpenseRequest;
use App\Http\Requests\Api\V1\Admin\StoreExpenseAttachmentRequest;
use App\Http\Requests\Api\V1\Admin\StoreExpenseRequest;
use App\Http\Requests\Api\V1\Admin\UpdateExpenseRequest;
use App\Http\Resources\ExpenseAttachmentResource;
use App\Http\Resources\ExpenseResource;
use App\Http\Responses\ApiResponse;
use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\Tenant;
use App\Services\Accounting\ExpenseService;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        $query = Expense::query()->with(['account', 'createdBy']);

        if ($request->filled('date_from')) {
            $query->whereDate('expense_date', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('expense_date', '<=', $request->string('date_to')->toString());
        }

        $expenses = ApiQuery::for($query, $request)
            ->searchable('expense_number', 'vendor', 'reference_number')
            ->filterable(['status', 'account_id', 'payment_method', 'created_by'])
            ->sortable(['expense_date', 'amount', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(ExpenseResource::collection($expenses));
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $expense = $this->expenses->create($request->validated(), $request->user());

        return ApiResponse::created(new ExpenseResource($expense->load(['account', 'createdBy'])));
    }

    public function show(Expense $expense): JsonResponse
    {
        $this->authorize('view', $expense);

        return ApiResponse::success(new ExpenseResource(
            $expense->load(['account', 'cashAccount', 'createdBy', 'approvedBy', 'cancelledBy', 'attachments.uploadedBy'])
        ));
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        $expense->update($request->validated());

        return ApiResponse::success(new ExpenseResource($expense->load(['account', 'createdBy'])));
    }

    public function approve(ApproveExpenseRequest $request, Expense $expense): JsonResponse
    {
        $expense = $this->expenses->approve($expense, $request->user());

        return ApiResponse::success(new ExpenseResource($expense));
    }

    public function reject(RejectExpenseRequest $request, Expense $expense): JsonResponse
    {
        $expense = $this->expenses->reject($expense, $request->validated('reason'), $request->user());

        return ApiResponse::success(new ExpenseResource($expense));
    }

    public function pay(PayExpenseRequest $request, Expense $expense): JsonResponse
    {
        $cashAccount = Account::query()->findOrFail($request->validated('cash_account_id'));

        $expense = $this->expenses->pay($expense, $cashAccount, $request->user(), $request->validated('paid_date'));

        return ApiResponse::success(new ExpenseResource($expense->load('cashAccount')));
    }

    public function cancel(CancelExpenseRequest $request, Expense $expense): JsonResponse
    {
        $expense = $this->expenses->cancel($expense, $request->validated('reason'), $request->user());

        return ApiResponse::success(new ExpenseResource($expense));
    }

    /** Reuses the existing `public` disk convention (Gallery/avatars) — see Tenant::storagePath(). */
    public function storeAttachment(StoreExpenseAttachmentRequest $request, Expense $expense): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $this->context->getOrFail();
        $file = $request->file('file');

        $path = $file->store($tenant->storagePath('expense-attachments'), 'public');

        $attachment = ExpenseAttachment::query()->create([
            'expense_id' => $expense->id,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'uploaded_by' => $request->user()->getKey(),
        ]);

        return ApiResponse::created(new ExpenseAttachmentResource($attachment->load('uploadedBy')));
    }

    public function destroyAttachment(Expense $expense, ExpenseAttachment $attachment): JsonResponse
    {
        $this->authorize('update', $expense);
        abort_unless($attachment->expense_id === $expense->id, 404);

        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();

        return ApiResponse::noContent();
    }
}
