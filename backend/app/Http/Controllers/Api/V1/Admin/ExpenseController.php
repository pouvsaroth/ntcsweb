<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ApproveExpenseRequest;
use App\Http\Requests\Api\V1\Admin\CancelExpenseRequest;
use App\Http\Requests\Api\V1\Admin\PayExpenseRequest;
use App\Http\Requests\Api\V1\Admin\RejectExpenseRequest;
use App\Http\Requests\Api\V1\Admin\StoreExpenseAttachmentRequest;
use App\Http\Requests\Api\V1\Admin\StoreExpenseBatchRequest;
use App\Http\Requests\Api\V1\Admin\StoreExpenseRequest;
use App\Http\Requests\Api\V1\Admin\UpdateExpenseRequest;
use App\Http\Resources\ExpenseAttachmentResource;
use App\Http\Resources\ExpenseResource;
use App\Http\Responses\ApiResponse;
use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ExpenseService;
use App\Services\Approvals\ApprovalFlow;
use App\Support\Approvals\DocumentType;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use App\Support\Accounting\ExpenseStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly TenantContext $context,
        private readonly ApprovalFlow $flow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canViewAll = $user->can('viewAny', Expense::class);
        // A member of an Expense flow step's group sees the expenses waiting
        // on them in the Approvals queue even without expense.view.
        abort_unless($canViewAll || $this->flow->isApproverFor($user, [DocumentType::EXPENSE]), 403);

        $query = Expense::query()->with(['account', 'expenseEntity', 'createdBy']);

        if ($request->boolean('approval_queue') || ! $canViewAll) {
            $this->flow->scopeQueue($query, Expense::class, $user, $canViewAll);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('expense_date', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('expense_date', '<=', $request->string('date_to')->toString());
        }

        // For month ('YYYY-MM'): the month it belongs to, not the day money moved.
        $forMonth = $request->input('filter.for_month', $request->input('for_month'));
        if (is_string($forMonth) && $forMonth !== '') {
            validator(['for_month' => $forMonth], ['for_month' => ['date_format:Y-m']])->validate();
            $query->forMonth($forMonth);
        }

        $expenses = ApiQuery::for($query, $request)
            ->searchable('expense_number', 'vendor', 'reference_number')
            ->filterable(['status', 'account_id', 'payment_method', 'created_by', 'supplier_id', 'expense_entity_id'])
            ->sortable(['expense_date', 'for_month', 'amount', 'created_at'], default: '-created_at')
            ->paginate();

        $this->flow->attachProgress($expenses->getCollection(), $user);

        return ApiResponse::success(ExpenseResource::collection($expenses));
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $expense = $this->expenses->create($request->validated(), $request->user());

        return ApiResponse::created(new ExpenseResource($expense->load(['account', 'expenseEntity', 'createdBy'])));
    }

    /**
     * Add many: one expense per line, all on the same date with the same
     * Expense for / currency / payment method / vendor. Every line is
     * validated first and they are saved together — one bad line saves none.
     */
    public function storeBatch(StoreExpenseBatchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $shared = array_intersect_key($data, array_flip(['expense_date', 'for_month', 'expense_entity_id', 'currency', 'payment_method', 'supplier_id']));

        $expenses = DB::transaction(fn () => array_map(
            fn (array $line) => $this->expenses->create([...$shared, ...$line], $request->user()),
            array_values($data['lines']),
        ));

        return ApiResponse::created(ExpenseResource::collection(collect($expenses)->each->load(['account', 'expenseEntity', 'createdBy'])));
    }

    public function show(Request $request, Expense $expense): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('view', $expense) || $this->flow->isCurrentApprover($expense, $user), 403);

        return $this->full($expense, $user);
    }

    /**
     * The whole expense, as show() returns it. Every action answers with this
     * too: the detail page swaps in what comes back, and a missing part (e.g.
     * attachments after Pay) would leave it blank.
     */
    private function full(Expense $expense, User $user): JsonResponse
    {
        $expense->load(['account', 'expenseEntity', 'cashAccount', 'createdBy', 'approvedBy', 'cancelledBy', 'attachments.uploadedBy']);
        $this->flow->attachProgress([$expense], $user);

        return ApiResponse::success(new ExpenseResource($expense));
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        $data = $request->validated();
        // A picked vendor's name is kept too (see ExpenseService::createRow()).
        if (! empty($data['supplier_id'])) {
            $data['vendor'] = Supplier::query()->find($data['supplier_id'])?->name;
        }
        $expense->update($data);

        return $this->full($expense, $request->user());
    }

    public function approve(ApproveExpenseRequest $request, Expense $expense): JsonResponse
    {
        // With an Expense flow: approves the current step (and tells the
        // next step's group); the last step approves the expense.
        $expense = $this->flow->approve(
            $expense,
            $request->user(),
            fn (Expense $doc) => $this->expenses->approve($doc, $request->user()),
            fn (Expense $doc, $nextApprovers) => $this->expenses->notifyApprovers($doc, $nextApprovers),
        );

        return $this->full($expense, $request->user());
    }

    public function reject(RejectExpenseRequest $request, Expense $expense): JsonResponse
    {
        $expense = $this->expenses->reject($expense, $request->validated('reason'), $request->user());

        return $this->full($expense, $request->user());
    }

    public function pay(PayExpenseRequest $request, Expense $expense): JsonResponse
    {
        $cashAccount = Account::query()->findOrFail($request->validated('cash_account_id'));

        $expense = $this->expenses->pay($expense, $cashAccount, $request->user(), $request->validated('paid_date'));

        return $this->full($expense, $request->user());
    }

    /**
     * Approve selected: each ticked expense is approved as if opened one by
     * one (flow step, self-approval rule). Those that can't be are skipped,
     * each with its reason.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_MAX], 'ids.*' => ['integer']])['ids'];
        $user = $request->user();

        return $this->eachExpense($ids, function (Expense $expense) use ($user) {
            if ($expense->status !== ExpenseStatus::PENDING_APPROVAL) {
                throw ValidationException::withMessages(['status' => __('Not waiting for approval.')]);
            }
            if (! $this->flow->mayDecide($expense, $user, 'approve')) {
                throw new AuthorizationException(__('Not waiting for your approval.'));
            }
            $this->flow->approve(
                $expense,
                $user,
                fn (Expense $doc) => $this->expenses->approve($doc, $user),
                fn (Expense $doc, $nextApprovers) => $this->expenses->notifyApprovers($doc, $nextApprovers),
            );
        });
    }

    /** Pay selected: approved ones are paid from one cash/bank account on one date; the rest are skipped with a reason. */
    public function bulkPay(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::EXPENSE_PAY), 403);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_MAX],
            'ids.*' => ['integer'],
            'cash_account_id' => ['required', Rule::exists('tenant.accounts', 'id')->where('is_bank_or_cash', true)->where('is_active', true)],
            'paid_date' => ['nullable', 'date'],
        ]);
        $cashAccount = Account::query()->findOrFail($data['cash_account_id']);
        $user = $request->user();

        return $this->eachExpense($data['ids'], function (Expense $expense) use ($cashAccount, $user, $data) {
            if ($expense->status !== ExpenseStatus::APPROVED) {
                throw ValidationException::withMessages(['status' => $expense->status === ExpenseStatus::PAID ? __('Already paid.') : __('Not approved yet.')]);
            }
            $this->expenses->pay($expense, $cashAccount, $user, $data['paid_date'] ?? null);
        });
    }

    /** Up to this many ticked at once. */
    private const BULK_MAX = 200;

    /**
     * Runs one action per expense, each on its own; a failure skips only that one.
     *
     * @param  list<int>  $ids
     */
    private function eachExpense(array $ids, callable $action): JsonResponse
    {
        $done = [];
        $skipped = [];
        foreach (Expense::query()->whereIn('id', array_unique($ids))->orderBy('id')->get() as $expense) {
            try {
                $action($expense);
                $done[] = $expense->id;
            } catch (ValidationException $e) {
                $skipped[] = ['id' => $expense->id, 'expense_number' => $expense->expense_number, 'reason' => collect($e->errors())->flatten()->first() ?? $e->getMessage()];
            } catch (AuthorizationException $e) {
                $skipped[] = ['id' => $expense->id, 'expense_number' => $expense->expense_number, 'reason' => $e->getMessage()];
            }
        }

        return ApiResponse::success(['done' => $done, 'skipped' => $skipped]);
    }

    public function cancel(CancelExpenseRequest $request, Expense $expense): JsonResponse
    {
        $expense = $this->expenses->cancel($expense, $request->validated('reason'), $request->user());

        return $this->full($expense, $request->user());
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
