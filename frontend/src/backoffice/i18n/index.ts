import { createI18n } from 'vue-i18n'

import appEn, { type MessageSchema } from '@/i18n/locales/en'
import appKm from '@/i18n/locales/km'

import en, { type BackofficeMessages } from './en'
import km from './km'

/**
 * English and Khmer only — the back office is used by our own staff.
 *
 * The shared UI components (DataTable, BaseModal, ConfirmDialog, …) read
 * `common.*` keys from the school app's catalogue, so those are merged in
 * underneath; the back office's own strings sit under `bo.*`.
 */
export type BackofficeLocale = 'en' | 'km'

const STORAGE_KEY = 'ntcsweb.backoffice.locale'

function initialLocale(): BackofficeLocale {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (stored === 'en' || stored === 'km') return stored
  } catch {
    // Storage blocked — fall through to the browser language.
  }
  return navigator.language?.startsWith('km') ? 'km' : 'en'
}

type Messages = { common: MessageSchema['common']; bo: BackofficeMessages }

export const i18n = createI18n<[Messages], BackofficeLocale, false>({
  legacy: false,
  locale: initialLocale(),
  fallbackLocale: 'en',
  messages: {
    en: { common: appEn.common, bo: en },
    km: { common: appKm.common, bo: km },
  },
})

export function setLocale(locale: BackofficeLocale): void {
  i18n.global.locale.value = locale
  document.documentElement.lang = locale
  try {
    localStorage.setItem(STORAGE_KEY, locale)
  } catch {
    // Not fatal: the choice just will not survive a reload.
  }
}

document.documentElement.lang = i18n.global.locale.value
