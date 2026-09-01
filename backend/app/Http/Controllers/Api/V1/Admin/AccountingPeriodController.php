<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CloseAccountingPeriodRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AccountingPeriod;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AccountingPeriodController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permissions::ACCOUNTING_PERIOD_CLOSE), 403);

        return ApiResponse::success(
            AccountingPeriod::query()->orderByDesc('period')->get(['period', 'closed_at', 'closed_by'])
        );
    }

    public function close(CloseAccountingPeriodRequest $request): JsonResponse
    {
        $tenant = $this->context->getOrFail();
        $period = $request->validated('period');

        $already = AccountingPeriod::query()->where('period', $period)->exists();

        if ($already) {
            throw ValidationException::withMessages(['period' => "The period {$period} is already closed."]);
        }

        $closed = AccountingPeriod::query()->create([
            'period' => $period,
            'closed_at' => now(),
            'closed_by' => $request->user()->getKey(),
        ]);

        $this->audit->log(
            AuditAction::ACCOUNTING_PERIOD_CLOSED,
            'Accounting',
            $closed,
            new: ['period' => $period],
            description: "Closed accounting period {$period}",
            actor: $request->user(),
            tenantId: $tenant->getKey(),
        );

        return ApiResponse::created(['period' => $closed->period, 'closed_at' => $closed->closed_at->toIso8601String()]);
    }
}
