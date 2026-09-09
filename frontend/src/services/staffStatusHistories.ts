import { apiGetWithMeta } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface StaffStatusHistoryEntry {
  id: number
  staff: { id: number; employee_code: string; full_name: string }
  from_status: string
  to_status: string
  reason: string | null
  requested_date: string | null
  effective_date: string | null
  changed_by: string | null
  created_at: string
}

export interface StaffStatusHistoryQuery extends PaginatedQuery {
  date_from?: string
  date_to?: string
}

/**
 * Read-only, deliberately — see StaffStatusHistoryController's docblock.
 * Writing a new entry only ever happens through staffService.changeStatus().
 */
export const staffStatusHistoriesService = {
  async list(query: StaffStatusHistoryQuery): Promise<PaginatedResult<StaffStatusHistoryEntry>> {
    const result = await apiGetWithMeta<StaffStatusHistoryEntry[]>('/staff-status-histories', {
      params: {
        page: query.page,
        per_page: query.per_page,
        sort: query.sort,
        filter: query.filter,
        date_from: query.date_from,
        date_to: query.date_to,
      },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
}
