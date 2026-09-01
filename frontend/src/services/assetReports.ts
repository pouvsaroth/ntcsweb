import { apiGet, apiGetWithMeta } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { AssetAssignment, AssetHistoryEntry, Asset } from '@/services/assets'
import type { AssetMaintenance } from '@/services/assetMaintenance'
import type { AssetRepair } from '@/services/assetRepairs'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface AssetStatusReport {
  counts_by_status: Record<string, number>
  counts_by_category: { category_id: number; category_name: string; total: number }[]
  counts_by_location: { location_id: number; location_name: string; total: number }[]
}

export interface AssetRepairCostReport {
  total_repair_cost: number
  by_repair_shop: { repair_shop_id: number; repair_shop_name: string; repair_count: number; total_cost: number }[]
}

export const assetReportsService = {
  async inventory(query: PaginatedQuery): Promise<PaginatedResult<Asset>> {
    const result = await apiGetWithMeta<Asset[]>('/assets/reports/inventory', {
      params: { page: query.page, per_page: query.per_page, search: query.search, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  status: () => apiGet<AssetStatusReport>('/assets/reports/status'),

  async repairs(query: PaginatedQuery): Promise<PaginatedResult<AssetRepair>> {
    const result = await apiGetWithMeta<AssetRepair[]>('/assets/reports/repairs', {
      params: { page: query.page, per_page: query.per_page, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  repairCost: (params: { date_from?: string; date_to?: string }) => apiGet<AssetRepairCostReport>('/assets/reports/repair-cost', { params }),

  async maintenance(query: PaginatedQuery): Promise<PaginatedResult<AssetMaintenance>> {
    const result = await apiGetWithMeta<AssetMaintenance[]>('/assets/reports/maintenance', {
      params: { page: query.page, per_page: query.per_page, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  async assignments(query: PaginatedQuery): Promise<PaginatedResult<AssetAssignment>> {
    const result = await apiGetWithMeta<AssetAssignment[]>('/assets/reports/assignments', {
      params: { page: query.page, per_page: query.per_page, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  async history(query: PaginatedQuery & { date_from?: string; date_to?: string }): Promise<PaginatedResult<AssetHistoryEntry>> {
    const result = await apiGetWithMeta<AssetHistoryEntry[]>('/assets/reports/history', {
      params: { page: query.page, per_page: query.per_page, filter: query.filter, date_from: query.date_from, date_to: query.date_to },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
}
