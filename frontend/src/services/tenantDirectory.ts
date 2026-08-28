import { apiGet } from '@/services/http'

export interface TenantOption {
  id: number
  slug: string
  name: string
}

/**
 * `GET /api/v1/tenants` — unauthenticated, platform-wide, deliberately
 * outside the tenant-scoped `/public/*` group (that group requires a tenant
 * to already be resolved, which is exactly what this endpoint exists to
 * avoid needing). Backs the login/forgot-password "School" dropdown.
 */
export const tenantDirectoryService = {
  async list(): Promise<TenantOption[]> {
    return apiGet<TenantOption[]>('/tenants', { params: { per_page: 100 } })
  },
}
