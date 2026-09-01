import { apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type MaintenanceStatus = 'SCHEDULED' | 'IN_PROGRESS' | 'COMPLETED' | 'CANCELLED'

export const maintenanceStatuses: MaintenanceStatus[] = ['SCHEDULED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED']

export interface AssetMaintenance {
  id: number
  maintenance_number: string
  asset_id: number
  asset: { id: number; asset_number: string; name: string } | null
  maintenance_type: string
  scheduled_date: string | null
  completed_date: string | null
  description: string | null
  repair_shop_id: number | null
  repair_shop: { id: number; name: string } | null
  cost: number | null
  status: MaintenanceStatus
  is_overdue: boolean
  recurrence_interval_months: number | null
  next_maintenance_date: string | null
  notes: string | null
  created_at: string
}

export interface ScheduleMaintenanceInput {
  maintenance_type: string
  scheduled_date: string
  description: string
  repair_shop_id: number | null
  recurrence_interval_months: number | null
  notes: string
}

export const assetMaintenanceService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<AssetMaintenance>> {
    const result = await apiGetWithMeta<AssetMaintenance[]>('/asset-maintenance', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<AssetMaintenance>(`/asset-maintenance/${id}`).then((r) => r.data),

  schedule: (assetId: number, input: ScheduleMaintenanceInput) => apiPost<AssetMaintenance>(`/assets/${assetId}/maintenance`, input),

  complete: (id: number, input: { completed_date?: string; cost?: string; description?: string }) =>
    apiPost<AssetMaintenance>(`/asset-maintenance/${id}/complete`, { ...input, cost: input.cost ? Number(input.cost) : undefined }),

  cancel: (id: number) => apiPost<AssetMaintenance>(`/asset-maintenance/${id}/cancel`),
}
