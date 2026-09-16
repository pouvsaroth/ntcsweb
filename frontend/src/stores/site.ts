import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { useFavicon } from '@vueuse/core'

import { apiGet } from '@/services/http'
import { ApiRequestError } from '@/types/api'

export interface AboutStat {
  value: string
  label: string
}

export interface AboutPillar {
  icon: string
  title: string
  description: string
}

export interface AboutAchievement {
  icon: string
  value: string
  label: string
}

export interface AboutContent {
  history_title: string
  history_paragraph_1: string
  history_paragraph_2: string
  history_image_url: string | null
  stats: AboutStat[]
  pillars: AboutPillar[]
  achievements_title: string
  achievements: AboutAchievement[]
}

export interface SiteDocuments {
  school_regulation_url: string | null
  student_attendance_policy_url: string | null
}

export interface PublicSiteInfo {
  name: string
  /** Optional Latin-script name shown below `name` on the Login page — `name` itself is free-text and often in Khmer. */
  name_en: string | null
  logo: string | null
  email: string | null
  phone: string | null
  address: string | null
  /** null until the school has saved About content at least once. */
  about: AboutContent | null
  /** The "Document and Form" menu's two fixed downloads — null until uploaded, see School Documents. */
  documents: SiteDocuments
  /** Whether the registration wizard's payment step should offer QR (Bakong KHQR) as an option — see School Settings. */
  has_khqr: boolean
}

const FALLBACK: PublicSiteInfo = {
  name: 'NTCSWEB',
  name_en: null,
  logo: null,
  email: null,
  phone: null,
  address: null,
  about: null,
  documents: { school_regulation_url: null, student_attendance_policy_url: null },
  has_khqr: false,
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

  // Reactively swaps the browser tab's favicon to the school's own logo the
  // moment `load()` resolves — falls back to the static default icon for an
  // unresolved tenant (central domain) or a school with no logo uploaded
  // yet. Wired here rather than per-layout so it applies everywhere the
  // tenant is resolved (public site, admin panel, auth screens alike).
  useFavicon(computed(() => info.value.logo ?? '/favicon.svg'))

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
