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

export interface MeResult {
  user: User
  permissions: string[] | ['*']
  is_super_admin: boolean
  tenant: { id: number; name: string } | null
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
  login(payload: LoginPayload) {
    return withCsrf(() => apiPost<{ user: User }>('/auth/login', payload))
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
