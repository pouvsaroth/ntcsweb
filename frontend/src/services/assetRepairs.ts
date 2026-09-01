import { apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { AssetCondition } from '@/services/assets'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type RepairStatus = 'PENDING' | 'SENT_TO_SHOP' | 'RECEIVED_BY_SHOP' | 'UNDER_REPAIR' | 'WAITING_FOR_PARTS' | 'REPAIR_COMPLETED' | 'RETURNED' | 'CANCELLED'

export const repairStatuses: RepairStatus[] = [
  'PENDING', 'SENT_TO_SHOP', 'RECEIVED_BY_SHOP', 'UNDER_REPAIR', 'WAITING_FOR_PARTS', 'REPAIR_COMPLETED', 'RETURNED', 'CANCELLED',
]

export type RepairDecision = 'REPAIR' | 'REPLACE' | 'RETIRE' | 'DISPOSE'

export const repairDecisions: RepairDecision[] = ['REPAIR', 'REPLACE', 'RETIRE', 'DISPOSE']

export interface AssetRepair {
  id: number
  repair_number: string
  asset_id: number
  asset: { id: number; asset_number: string; name: string } | null
  issue_id: number | null
  repair_shop_id: number | null
  repair_shop: { id: number; name: string } | null
  sent_date: string | null
  expected_return_date: string | null
  actual_return_date: string | null
  problem_description: string | null
  diagnosis: string | null
  repair_description: string | null
  status: RepairStatus
  diagnosis_cost: number
  parts_cost: number
  labor_cost: number
  transport_cost: number
  other_cost: number
  total_cost: number
  warranty_days: number | null
  condition_after_repair: AssetCondition | null
  decision: RepairDecision | null
  decision_by: string | null
  decision_date: string | null
  decision_reason: string | null
  expense_id: number | null
  notes: string | null
  created_at: string
}

export interface SendToRepairInput {
  repair_shop_id: number | null
  sent_date?: string
  expected_return_date?: string
  problem_description: string
  issue_id?: number | null
}

export interface CompleteRepairInput {
  expense_account_id: number
  repair_description: string
  condition_after_repair: AssetCondition | null
  warranty_days?: number | null
  diagnosis_cost: string
  parts_cost: string
  labor_cost: string
  transport_cost: string
  other_cost: string
}

export const assetRepairsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<AssetRepair>> {
    const result = await apiGetWithMeta<AssetRepair[]>('/asset-repairs', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<AssetRepair>(`/asset-repairs/${id}`).then((r) => r.data),

  sendToRepair: (assetId: number, input: SendToRepairInput) => apiPost<AssetRepair>(`/assets/${assetId}/repairs`, input),

  update: (
    id: number,
    input: Partial<{
      status: RepairStatus
      diagnosis: string
      repair_description: string
      diagnosis_cost: string
      parts_cost: string
      labor_cost: string
      transport_cost: string
      other_cost: string
    }>,
  ) => apiPut<AssetRepair>(`/asset-repairs/${id}`, input),

  complete: (id: number, input: CompleteRepairInput) =>
    apiPost<AssetRepair>(`/asset-repairs/${id}/complete`, {
      ...input,
      diagnosis_cost: Number(input.diagnosis_cost) || 0,
      parts_cost: Number(input.parts_cost) || 0,
      labor_cost: Number(input.labor_cost) || 0,
      transport_cost: Number(input.transport_cost) || 0,
      other_cost: Number(input.other_cost) || 0,
    }),

  decide: (id: number, decision: RepairDecision, reason: string) => apiPost<AssetRepair>(`/asset-repairs/${id}/decide`, { decision, reason }),

  cancel: (id: number, reason: string) => apiPost<AssetRepair>(`/asset-repairs/${id}/cancel`, { reason }),
}
