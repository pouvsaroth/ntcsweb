import { apiGetWithMeta } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'
import type { User } from '@/types/models'

/**
 * `GET /api/v1/admin/users` doesn't exist yet — it's Phase 6 (Admin API).
 * This is written against that endpoint's intended contract (same
 * search/filter/sort/pagination shape as every other admin list, per
 * App\Support\Query\ApiQuery on the backend) so the Users page needs no
 * changes once it ships.
 */
export const adminUsersService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<User>> {
    const result = await apiGetWithMeta<User[]>('/admin/users', {
      params: {
        page: query.page,
        per_page: query.per_page,
        search: query.search,
        sort: query.sort,
        filter: query.filter,
      },
    })

    const pagination = result.meta?.pagination as LengthAwarePaginationMeta

    return { data: result.data, pagination }
  },
}
