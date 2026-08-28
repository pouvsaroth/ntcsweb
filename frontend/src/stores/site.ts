import { ref } from 'vue'
import { defineStore } from 'pinia'

import { apiGet } from '@/services/http'
import { ApiRequestError } from '@/types/api'

export interface PublicSiteInfo {
  name: string
  logo: string | null
  email: string | null
  phone: string | null
  address: string | null
}

const FALLBACK: PublicSiteInfo = {
  name: 'NTCSWEB',
  logo: null,
  email: null,
  phone: null,
  address: null,
}

/**
 * Public-facing tenant branding (name/logo/contact) for the site header,
 * footer, and auth screens.
 *
 * `resolved` is the important part beyond branding: `GET /public/settings`
 * sits behind the `tenant.required` middleware, so a 404 here means *no
 * school could be determined from this hostname* — exactly the situation on
 * a central domain (localhost, no subdomain) where the login form has no way
 * to know which school's credentials to check. Callers use `resolved` to
 * decide whether to ask the visitor which school they mean; on a real
 * tenant subdomain in production this is always true and that prompt never
 * appears.
 */
export const useSiteStore = defineStore('site', () => {
  const info = ref<PublicSiteInfo>(FALLBACK)
  const loaded = ref(false)
  const resolved = ref(false)

  async function load(): Promise<void> {
    if (loaded.value) return
    try {
      info.value = await apiGet<PublicSiteInfo>('/public/settings')
      resolved.value = true
    } catch (error) {
      if (!(error instanceof ApiRequestError && error.status === 404)) {
        console.error('Failed to load public site settings', error)
      }
      info.value = FALLBACK
      resolved.value = false
    } finally {
      loaded.value = true
    }
  }

  return { info, loaded, resolved, load }
})
