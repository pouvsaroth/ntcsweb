import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import { authService, loginRequiresTenantSelection, type LoginPayload, type LoginResult, type MeResult } from '@/services/auth'
import { type ActingTenant, getActingTenant, resetDevTenant, setActingTenant, setDevTenant } from '@/services/http'
import type { User } from '@/types/models'
import { ApiRequestError } from '@/types/api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const permissions = ref<string[] | ['*']>([])
  const isSuperAdmin = ref(false)
  const tenantName = ref<string | null>(null)
  /** The signed-in school's billing currency — see EnrollmentPackageForm.vue, which converts a package's (usually USD) fee into this. Not set for a platform Super Admin browsing no particular school. */
  const tenantDefaultCurrency = ref<'USD' | 'KHR' | null>(null)
  /** Today's KHR-per-USD rate, or null if the school has never entered one under Currency Rates. */
  const khrPerUsdRate = ref<number | null>(null)
  /** The signed-in school's own public website hostname — see AdminHeader.vue's "Go to website" link. Null for a platform Super Admin browsing no particular school. */
  const tenantHostname = ref<string | null>(null)
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

  function applySession(result: MeResult) {
    user.value = result.user
    permissions.value = result.permissions
    isSuperAdmin.value = result.is_super_admin
    tenantName.value = result.tenant?.name ?? null
    tenantDefaultCurrency.value = result.tenant?.default_currency ?? null
    khrPerUsdRate.value = result.tenant?.khr_per_usd_rate ?? null
    tenantHostname.value = result.tenant?.hostname ?? null

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
    tenantDefaultCurrency.value = null
    khrPerUsdRate.value = null
    tenantHostname.value = null
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

  /**
   * Returns the raw LoginResult so the caller (Login.vue) can tell a
   * completed sign-in apart from "pick which school" — see
   * loginRequiresTenantSelection()'s docblock. Session state is only
   * applied once sign-in has actually finished (here, or in
   * selectTenant() below).
   */
  async function login(payload: LoginPayload, schoolFieldShown = false): Promise<LoginResult> {
    let result: LoginResult

    try {
      result = await authService.login(payload, schoolFieldShown)
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

      result = await authService.login(payload, true)
    }

    if (loginRequiresTenantSelection(result)) {
      return result
    }

    // The login response only returns the user; roles/permissions come from
    // /auth/me's meta, so a fresh fetch is the simplest way to get a fully
    // consistent session state rather than duplicating that shape here.
    const meResult = await authService.me()
    if (meResult) applySession(meResult)

    return result
  }

  /** Finishes a login that came back needing a school picked — see login() and Login.vue. No password is sent again; the selection token is what proves it already checked out. */
  async function selectTenant(payload: { selection_token: string; tenant_id: number; device_name?: string; remember?: boolean }): Promise<void> {
    await authService.selectTenant(payload)

    const meResult = await authService.me()
    if (meResult) applySession(meResult)
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
    tenantDefaultCurrency,
    khrPerUsdRate,
    tenantHostname,
    actingTenant,
    initialized,
    isAuthenticated,
    can,
    hasRole,
    initialize,
    login,
    selectTenant,
    logout,
    updateProfile,
    enterTenant,
    exitTenant,
  }
})
