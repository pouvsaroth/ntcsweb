<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Fund;
use App\Services\Fund\FundProvidersByMonthReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Fund > Providers by month: money in per provider / staff member per month (FundProvidersByMonthReport). */
final class FundProvidersByMonthController extends Controller
{
    public function index(Request $request, FundProvidersByMonthReport $report): JsonResponse
    {
        return ApiResponse::success($report->build($this->filters($request)));
    }

    public function excel(Request $request, FundProvidersByMonthReport $report): Response
    {
        $bytes = $report->excel($report->build($this->filters($request)));

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="fund-providers-by-month-'.now()->format('Y-m-d').'.xlsx"',
        ]);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $this->authorize('viewAny', Fund::class);

        return $request->validate([
            'from_month' => ['nullable', 'date_format:Y-m'],
            'to_month' => ['nullable', 'date_format:Y-m'],
            // '' everyone, providers, staff, provider:<id>, staff:<id>
            'who' => ['nullable', 'string', 'regex:/^(providers|staff|provider:\d+|staff:\d+)$/'],
            'fund_type' => ['nullable', 'string', 'max:50'],
        ]);
    }
}
