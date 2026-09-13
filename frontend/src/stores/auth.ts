import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import { authService, type LoginPayload } from '@/services/auth'
import { type ActingTenant, getActingTenant, resetDevTenant, setActingTenant, setDevTenant } from '@/services/http'
import type { User } from '@/types/models'
import { ApiRequestError } from '@/types/api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const permissions = ref<string[] | ['*']>([])
  const isSuperAdmin = ref(false)
  const tenantName = ref<string | null>(null)
  /** A Super Admin deliberately browsing one school's admin data — see setActingTenant. Restored from sessionStorage so a page refresh doesn't drop it. */
  const actingTenant = ref<ActingTenant | null>(getActingTenant())

  /** True once the initial /auth/me check has resolved, either way. Lets the
   *  router/App shell distinguish "still checking" from "confirmed logged out". */
  const initialized = ref(false)

  const isAuthenticated = computed(() => user.value !== null)

  function can(permission: string): boolean {
    if (isSuperAdmin.value) return true
    return (permissions.value as string[]).includes(permission)
  }

  function hasRole(...slugs: string[]): boolean {
    return user.value?.roles?.some((role) => slugs.includes(role.slug)) ?? false
  }

  function applySession(result: { user: User; permissions: string[] | ['*']; is_super_admin: boolean; tenant: { name: string } | null }) {
    user.value = result.user
    permissions.value = result.permissions
    isSuperAdmin.value = result.is_super_admin
    tenantName.value = result.tenant?.name ?? null

    // Dev only (see setDevTenant), and skipped entirely while actingTenant is
    // set — that's a deliberate choice (see enterTenant) this correction must
    // never overwrite. Otherwise a real session is authoritative over
    // whatever the pre-login boot logic in http.ts guessed: a super admin
    // gets no tenant pinned to every subsequent request (else a stale guess
    // from before login would 403 their own session via
    // EnsureTenantMatchesUser), and a tenant-bound user gets *their own*
    // tenant, correcting a guess that may have landed on the wrong school.
    if (!actingTenant.value) {
      setDevTenant(result.is_super_admin ? null : (result.user.tenant?.slug ?? null))
    }
  }

  function clearSession() {
    user.value = null
    permissions.value = []
    isSuperAdmin.value = false
    tenantName.value = null
  }

  /** Called once, at app start (see main.ts), to restore an existing session. */
  async function initialize(): Promise<void> {
    const result = await authService.me()
    if (result) {
      applySession(result)
    } else {
      clearSession()
    }
    initialized.value = true
  }

  async function login(payload: LoginPayload, schoolFieldShown = false): Promise<void> {
    try {
      await authService.login(payload, schoolFieldShown)
    } catch (error) {
      // Dev only, and only when the school picker never even appeared (the
      // ordinary case on a fresh local boot — see http.ts's auto-detected
      // ambient tenant): that ambient guess is what the failed attempt above
      // just used, so a platform Super Admin account (no tenant of its own)
      // always fails it. Retrying once with no tenant at all is what a real
      // central-domain deployment would do the instant the (production-only)
      // school picker rendered and was left blank — this just reaches the
      // same outcome without that picker existing to interact with locally.
      // A genuinely wrong password still fails both attempts identically.
      const canRetryAsPlatformAdmin = import.meta.env.DEV && !schoolFieldShown && error instanceof ApiRequestError

      if (!canRetryAsPlatformAdmin) throw error

      await authService.login(payload, true)
    }

    // The login response only returns the user; roles/permissions come from
    // /auth/me's meta, so a fresh fetch is the simplest way to get a fully
    // consistent session state rather than duplicating that shape here.
    const result = await authService.me()
    if (result) applySession(result)
  }

  async function logout(): Promise<void> {
    try {
      await authService.logout()
    } finally {
      clearSession()
      resetDevTenant()
      setActingTenant(null)
      actingTenant.value = null
    }
  }

  /**
   * Steps a Super Admin into one school's admin data (Classes, Students,
   * everything else that's normally tenant-scoped) — a hard navigation, not
   * a route push, so every already-mounted page refetches under the new
   * X-Tenant rather than keeping data loaded for whatever was active before.
   */
  function enterTenant(tenant: ActingTenant): void {
    setActingTenant(tenant)
    window.location.assign('/admin')
  }

  /** Back to platform-only view (Tenants, Backup Database) — see enterTenant. */
  function exitTenant(): void {
    setActingTenant(null)
    window.location.assign('/admin/tenants')
  }

  /** Roles/permissions/tenant don't change from a profile edit — only the user object itself needs replacing. */
  async function updateProfile(payload: { name: string; phone: string; avatar?: File | null }): Promise<void> {
    user.value = await authService.updateProfile(payload)
  }

  return {
    user,
    permissions,
    isSuperAdmin,
    tenantName,
    actingTenant,
    initialized,
    isAuthenticated,
    can,
    hasRole,
    initialize,
    login,
    logout,
    updateProfile,
    enterTenant,
    exitTenant,
  }
})
