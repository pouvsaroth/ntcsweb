import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { authApi, type Session } from './api'

/**
 * Who is signed in to the back office — the answer to GET /auth/session,
 * asked once and remembered. A UX hint only: every platform route checks
 * again on the server.
 */
export const useSession = defineStore('backofficeSession', () => {
  const session = ref<Session | null>(null)
  let loading: Promise<Session | null> | null = null

  const staff = computed(() => session.value?.staff ?? null)
  const mustChangePassword = computed(() => session.value?.must_change_password ?? false)

  function can(permission: string): boolean {
    return session.value?.permissions.includes(permission) ?? false
  }

  function current(): Promise<Session | null> {
    loading ??= authApi
      .session()
      .then((s) => (session.value = s))
      .catch(() => (session.value = null))
    return loading
  }

  function set(next: Session): void {
    session.value = next
    loading = Promise.resolve(next)
  }

  /** The server said 401 to something: forget the session. */
  function expired(): void {
    session.value = null
    loading = null
  }

  async function signOut(): Promise<void> {
    try {
      await authApi.logout()
    } finally {
      expired()
    }
  }

  return { session, staff, mustChangePassword, can, current, set, expired, signOut }
})
