<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expense_number' => $this->expense_number,
            'expense_date' => $this->expense_date?->toDateString(),
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'code' => $this->account->code,
                'name' => $this->account->name,
            ]),
            'cash_account' => $this->whenLoaded('cashAccount', fn () => $this->cashAccount !== null ? [
                'id' => $this->cashAccount->id,
                'code' => $this->cashAccount->code,
                'name' => $this->cashAccount->name,
            ] : null),
            'amount' => (float) $this->amount,
            'payment_method' => $this->payment_method,
            'vendor' => $this->vendor,
            'description' => $this->description,
            'reference_number' => $this->reference_number,
            'status' => $this->status,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_reason' => $this->rejected_reason,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy?->name),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'attachments' => ExpenseAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
