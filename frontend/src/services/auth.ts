import { apiGetWithMeta, apiPost, primeCsrfCookie } from '@/services/http'
import type { User } from '@/types/models'

export interface LoginPayload {
  /** An email address or a phone number — the backend tries both. */
  login: string
  password: string
  remember?: boolean
  /** Only set for a future mobile/token client — the admin/public SPA never sends this. */
  device_name?: string
  /** Only honoured by the backend on a central domain (local dev without a subdomain). */
  tenant?: string
}

/** A school this identity has a verified account at — see AuthController::loginAcrossTenants(). */
export interface LoginTenantChoice {
  id: number
  slug: string
  name: string
}

/**
 * The shared ERP domain's login can't always finish in one step: if the
 * password verified at more than one school, the server holds off on
 * actually signing anyone in and hands back `tenants` to pick from instead —
 * see selectTenant() below, which is what finishes it.
 */
export type LoginResult = { user: User } | { requires_tenant_selection: true; selection_token: string; tenants: LoginTenantChoice[] }

export function loginRequiresTenantSelection(
  result: LoginResult,
): result is { requires_tenant_selection: true; selection_token: string; tenants: LoginTenantChoice[] } {
  return 'requires_tenant_selection' in result
}

export interface MeResult {
  user: User
  permissions: string[] | ['*']
  is_super_admin: boolean
  tenant: {
    id: number
    name: string
    default_currency: 'USD' | 'KHR'
    /** Today's KHR-per-USD rate, or null if the school has never entered one — see CurrencyConversionService. */
    khr_per_usd_rate: number | null
  } | null
}

/**
 * Every mutating auth call primes the CSRF cookie first. Sanctum's session
 * guard rejects a POST with no valid XSRF-TOKEN, and priming unconditionally
 * (rather than only once per app load) costs one cheap GET but avoids a whole
 * class of "works after refresh, fails on first load" bugs.
 */
async function withCsrf<T>(fn: () => Promise<T>): Promise<T> {
  await primeCsrfCookie()
  return fn()
}

export const authService = {
  /**
   * `schoolFieldShown` — pass `true` whenever Login.vue is on a central
   * domain and deliberately not sending a `tenant` (the manual school field
   * on admin.ntcsweb.com/localhost left blank — a platform Super Admin —
   * or the shared ERP domain's single-page form, which never sends one at
   * all; see AuthController::loginAcrossTenants()). In dev, the
   * instance-wide X-Tenant default (see http.ts) already resolves the
   * *common* case correctly — a tenant-scoped admin on a fresh boot, where
   * none of the above applies — so this must NOT touch headers then, or it
   * silently blanks that default and breaks that ordinary login. Every
   * other case must beat the ambient default for this one request, since
   * `RequestTenantResolver` treats an empty value the same as absent. No-op
   * in production, where that default header never exists in the first
   * place.
   */
  login(payload: LoginPayload, schoolFieldShown = false) {
    const headers = import.meta.env.DEV && schoolFieldShown ? { 'X-Tenant': payload.tenant ?? '' } : undefined

    return withCsrf(() => apiPost<LoginResult>('/auth/login', payload, { headers }))
  },

  /** Step two after login() comes back with `requires_tenant_selection` — see LoginResult's docblock. */
  selectTenant(payload: { selection_token: string; tenant_id: number; device_name?: string; remember?: boolean }) {
    return withCsrf(() => apiPost<{ user: User }>('/auth/login/select-tenant', payload))
  },

  logout() {
    return withCsrf(() => apiPost<void>('/auth/logout'))
  },

  /** Returns null on 401 rather than throwing — "am I logged in?" is a query, not an error. */
  async me(): Promise<MeResult | null> {
    try {
      const result = await apiGetWithMeta<User>('/auth/me')
      return {
        user: result.data,
        permissions: (result.meta?.permissions as string[] | ['*'] | undefined) ?? [],
        is_super_admin: Boolean(result.meta?.is_super_admin),
        tenant: (result.meta?.tenant as MeResult['tenant']) ?? null,
      }
    } catch {
      return null
    }
  },

  forgotPassword(email: string, tenant?: string) {
    return withCsrf(() => apiPost<void>('/auth/forgot-password', { email, tenant }))
  },

  resetPassword(payload: {
    token: string
    email: string
    password: string
    password_confirmation: string
    tenant?: string
  }) {
    return withCsrf(() => apiPost<void>('/auth/reset-password', payload))
  },

  changePassword(payload: {
    current_password: string
    password: string
    password_confirmation: string
  }) {
    return apiPost<void>('/auth/change-password', payload)
  },

  /** Multipart — `avatar` is only appended when the admin actually picked a new file. */
  updateProfile(payload: { name: string; phone: string; avatar?: File | null }) {
    const form = new FormData()
    form.append('name', payload.name)
    form.append('phone', payload.phone)
    if (payload.avatar) form.append('avatar', payload.avatar)

    return apiPost<User>('/auth/me', form)
  },
}
