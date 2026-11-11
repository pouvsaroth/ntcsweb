import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface Role {
  id: number
  slug: string
  name: string
  description: string | null
  level: number
  /** Data view: every class, or only the user's assigned classes. */
  data_scope: DataScope
  is_system: boolean
  is_platform: boolean
  permissions?: string[]
  users_count?: number
}

export type DataScope = 'all' | 'assigned'

export interface RoleInput {
  name: string
  description: string
  level: number
  data_scope: DataScope
  permissions: string[]
}

export const rolesService = {
  async list(query: Partial<PaginatedQuery> = {}): Promise<PaginatedResult<Role>> {
    const result = await apiGetWithMeta<Role[]>('/roles', {
      params: { page: query.page, per_page: query.per_page ?? 100, search: query.search, sort: query.sort },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every role in this school, for the Staff/User forms' "grant a role" selects. */
  async listAll(): Promise<Role[]> {
    const result = await this.list({ per_page: 200 })
    return result.data
  },

  create: (input: RoleInput) => apiPost<Role>('/roles', input),
  update: (id: number, input: Partial<RoleInput>) => apiPut<Role>(`/roles/${id}`, input),
  remove: (id: number) => apiDelete(`/roles/${id}`),
}
