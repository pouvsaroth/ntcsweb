<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Expense;
use App\Services\Approvals\ApprovalFlow;
use Illuminate\Foundation\Http\FormRequest;

class RejectExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Expense $expense */
        $expense = $this->route('expense');

        // With an Expense flow: only the current step's group; without: the policy ability.
        return $this->user() !== null && app(ApprovalFlow::class)->mayDecide($expense, $this->user(), 'reject');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
