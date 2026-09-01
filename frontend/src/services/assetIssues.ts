import { apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type IssuePriority = 'LOW' | 'MEDIUM' | 'HIGH' | 'CRITICAL'

export const issuePriorities: IssuePriority[] = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']

export type IssueStatus = 'OPEN' | 'ACKNOWLEDGED' | 'UNDER_INSPECTION' | 'WAITING_FOR_PART' | 'SENT_TO_REPAIR' | 'REPAIRED' | 'RESOLVED' | 'CLOSED' | 'CANCELLED'

export const issueStatuses: IssueStatus[] = [
  'OPEN', 'ACKNOWLEDGED', 'UNDER_INSPECTION', 'WAITING_FOR_PART', 'SENT_TO_REPAIR', 'REPAIRED', 'RESOLVED', 'CLOSED', 'CANCELLED',
]

export interface AssetIssue {
  id: number
  issue_number: string
  asset_id: number
  asset: { id: number; asset_number: string; name: string } | null
  reported_by: string | null
  reported_date: string | null
  priority: IssuePriority
  status: IssueStatus
  title: string
  description: string | null
  resolved_at: string | null
  resolved_by: string | null
  created_at: string
}

export interface ReportIssueInput {
  title: string
  priority: IssuePriority
  description: string
  reported_date?: string
}

export const assetIssuesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<AssetIssue>> {
    const result = await apiGetWithMeta<AssetIssue[]>('/asset-issues', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<AssetIssue>(`/asset-issues/${id}`).then((r) => r.data),

  report: (assetId: number, input: ReportIssueInput) =>
    apiPost<AssetIssue>(`/assets/${assetId}/issues`, { ...input, description: input.description || null }),

  update: (id: number, input: { status?: IssueStatus; priority?: IssuePriority; description?: string }) =>
    apiPut<AssetIssue>(`/asset-issues/${id}`, input),

  resolve: (id: number, notes?: string) => apiPost<AssetIssue>(`/asset-issues/${id}/resolve`, { notes }),
}
