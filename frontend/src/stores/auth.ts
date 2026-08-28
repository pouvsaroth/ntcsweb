import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import { authService, type LoginPayload } from '@/services/auth'
import type { User } from '@/types/models'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const permissions = ref<string[] | ['*']>([])
  const isSuperAdmin = ref(false)
  const tenantName = ref<string | null>(null)

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

  async function login(payload: LoginPayload): Promise<void> {
    await authService.login(payload)
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
    }
  }

  return {
    user,
    permissions,
    isSuperAdmin,
    tenantName,
    initialized,
    isAuthenticated,
    can,
    hasRole,
    initialize,
    login,
    logout,
  }
})
