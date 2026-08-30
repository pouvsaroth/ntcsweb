/**
 * Mirrors the backend API Resources (App\Http\Resources\*) field-for-field.
 * If a Resource's toArray() changes, this file changes with it.
 */

export interface Role {
  id: number
  slug: string
  name: string
  description: string | null
  level: number
  is_system: boolean
  is_platform: boolean
  permissions?: string[]
  users_count?: number
}

export interface TenantDomain {
  id: number
  hostname: string
  type: 'subdomain' | 'custom'
  is_primary: boolean
  verified: boolean
  verified_at: string | null
}

export interface Tenant {
  id: number
  name: string
  slug: string
  code: string | null
  logo: string | null
  email: string | null
  phone: string | null
  address: string | null
  timezone: string
  locale: string
  status: 'active' | 'suspended' | 'archived'
  hostname: string
  created_at: string
  settings?: Record<string, unknown>
  domains?: TenantDomain[]
  users_count?: number
}

export interface User {
  id: number
  name: string
  email: string | null
  phone: string | null
  avatar_url: string | null
  status: 'active' | 'invited' | 'suspended'
  locale: string | null
  email_verified: boolean
  last_login_at: string | null
  created_at: string
  roles?: Role[]
  tenant?: Tenant
  /** Only present on /auth/me, or when viewing your own account. */
  permissions?: string[] | ['*']
}
