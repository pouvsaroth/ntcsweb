import { apiGet, apiGetWithMeta } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface AuditLogUser {
  id: number
  name: string
  email: string | null
}

export interface AuditLogEntry {
  id: number
  action: string
  module: string | null
  description: string | null
  user: AuditLogUser | null
  auditable_type: string | null
  auditable_id: number | null
  record: string | null
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  ip_address: string | null
  user_agent: string | null
  request_method: string | null
  request_url: string | null
  created_at: string
}

export interface AuditLogQuery extends PaginatedQuery {
  date_from?: string
  date_to?: string
}

/**
 * Read-only, deliberately — see AuditLogPolicy/AuditLogController's own
 * docblocks. There is no create/update/delete here because there is no such
 * route on the backend: audit logs are written only by AuditLogger.
 */
export const auditLogsService = {
  async list(query: AuditLogQuery): Promise<PaginatedResult<AuditLogEntry>> {
    const result = await apiGetWithMeta<AuditLogEntry[]>('/audit-logs', {
      params: {
        page: query.page,
        per_page: query.per_page,
        search: query.search,
        sort: query.sort,
        filter: query.filter,
        date_from: query.date_from,
        date_to: query.date_to,
      },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGet<AuditLogEntry>(`/audit-logs/${id}`),
}
