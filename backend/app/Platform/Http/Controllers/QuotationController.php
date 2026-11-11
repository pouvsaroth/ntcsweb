<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Platform\Billing\QuotationExport;
use App\Platform\Billing\Quotations;
use App\Platform\Models\Quotation;
use App\Platform\Support\PlatformAudit;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Billing > Quotations: list, open, create, change, delete. */
final class QuotationController extends Controller
{
    public function __construct(
        private readonly Quotations $quotations,
        private readonly PlatformAudit $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Quotation::query()
            ->with(['tenant:id,name,slug', 'staff:id,name'])
            ->when($request->input('filter.from'), fn ($q, $d) => $q->whereDate('issue_date', '>=', $d))
            ->when($request->input('filter.to'), fn ($q, $d) => $q->whereDate('issue_date', '<=', $d));

        $page = ApiQuery::for($query, $request)
            ->searchable('number', 'customer_name', 'contact_name', 'phone', 'email')
            ->filterable(['status', 'currency', 'tenant_id'])
            ->sortable(['issue_date', 'number', 'total', 'created_at'], default: '-id')
            ->paginate();

        $page->through(fn (Quotation $q) => $this->quotations->present($q, withLines: false));

        return ApiResponse::success($page);
    }

    public function show(Quotation $quotation): JsonResponse
    {
        return ApiResponse::success($this->quotations->present($quotation->load(['lines', 'tenant', 'staff'])));
    }

    public function store(Request $request): JsonResponse
    {
        $quotation = $this->quotations->save(null, $this->validated($request), $request->user('staff'));
        $this->audit->record('billing.quotation_created', $quotation, new: $quotation->only(['number', 'customer_name', 'currency', 'total']), tenantId: $quotation->tenant_id);

        return ApiResponse::created($this->quotations->present($quotation->load(['tenant', 'staff'])));
    }

    public function update(Request $request, Quotation $quotation): JsonResponse
    {
        $old = $quotation->only(['customer_name', 'currency', 'status', 'total']);
        $quotation = $this->quotations->save($quotation, $this->validated($request), $request->user('staff'));
        $this->audit->record('billing.quotation_updated', $quotation, $old, $quotation->only(['customer_name', 'currency', 'status', 'total']), tenantId: $quotation->tenant_id);

        return ApiResponse::success($this->quotations->present($quotation->load(['tenant', 'staff'])));
    }

    public function destroy(Quotation $quotation): JsonResponse
    {
        $this->audit->record('billing.quotation_deleted', $quotation, $quotation->only(['number', 'customer_name', 'total']), tenantId: $quotation->tenant_id);
        $quotation->delete();

        return ApiResponse::noContent();
    }

    /** Download as Excel, PDF or image, in English or Khmer. */
    public function export(Request $request, Quotation $quotation, QuotationExport $export): Response
    {
        $data = $request->validate([
            'format' => ['required', Rule::in(QuotationExport::FORMATS)],
            'lang' => ['nullable', Rule::in(QuotationExport::LANGS)],
        ]);
        $file = $export->export($quotation, $data['format'], $data['lang'] ?? 'en');
        $this->audit->record('billing.quotation_exported', $quotation, new: ['number' => $quotation->number, 'format' => $data['format']], tenantId: $quotation->tenant_id);

        return response($file['body'], 200, [
            'Content-Type' => $file['type'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $central = (new Quotation)->getConnectionName();

        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', Rule::exists((new Tenant)->getConnectionName().'.tenants', 'id')],
            'customer_name' => ['required', 'string', 'max:200'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'currency' => ['required', Rule::in(Quotation::CURRENCIES)],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'discount_type' => ['required', Rule::in(['amount', 'percent'])],
            'discount_value' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'tax_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(Quotation::STATUSES)],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.item_id' => ['nullable', 'integer', Rule::exists($central.'.bo_quotation_items', 'id')],
            'lines.*.name' => ['nullable', 'required_without:lines.*.item_id', 'string', 'max:200'],
            'lines.*.name_km' => ['nullable', 'string', 'max:200'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:99999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999999'],
        ]);
        return $data;
    }
}
