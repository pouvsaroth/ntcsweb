import { createI18n } from 'vue-i18n'

import en, { type MessageSchema } from './locales/en'
import ja from './locales/ja'
import km from './locales/km'
import ko from './locales/ko'
import zh from './locales/zh'

export const SUPPORTED_LOCALES = ['en', 'km', 'zh', 'ko', 'ja'] as const
export type Locale = (typeof SUPPORTED_LOCALES)[number]

/** Native (endonym) names — always shown in their own script, never translated. */
export const LOCALE_NAMES: Record<Locale, string> = {
  en: 'English',
  km: 'ភាសាខ្មែរ',
  zh: '中文',
  ko: '한국어',
  ja: '日本語',
}

/** Flag emoji standing in for each language in the switcher — a country flag isn't strictly a language flag, but it's the immediately recognisable convention every language switcher uses. */
export const LOCALE_FLAGS: Record<Locale, string> = {
  en: '🇺🇸',
  km: '🇰🇭',
  zh: '🇨🇳',
  ko: '🇰🇷',
  ja: '🇯🇵',
}

const STORAGE_KEY = 'ntcsweb.locale'

function isSupportedLocale(value: string | null): value is Locale {
  return value !== null && (SUPPORTED_LOCALES as readonly string[]).includes(value)
}

/**
 * Resolution order: an explicit prior choice (localStorage) beats the
 * browser's language, which beats the hard default. `navigator.language`
 * ("km-KH", "zh-CN", ...) is matched by its primary subtag only.
 */
function detectInitialLocale(): Locale {
  const stored = localStorage.getItem(STORAGE_KEY)
  if (isSupportedLocale(stored)) return stored

  const browserPrimary = navigator.language?.split('-')[0]
  if (isSupportedLocale(browserPrimary)) return browserPrimary

  return 'en'
}

// The third type argument (Legacy) has to be explicit: createI18n's return
// type is chosen from the *inferred* Options type, and a bare `legacy: false`
// literal in the options object widens to `boolean` under inference, which
// falls through to the Legacy generic's own default (true) rather than the
// runtime value — silently typing `i18n.global` as the legacy (non-Composer)
// API, where `.locale` isn't a Ref. Naming `false` here pins it correctly.
export const i18n = createI18n<[MessageSchema], Locale, false>({
  legacy: false,
  locale: detectInitialLocale(),
  fallbackLocale: 'en',
  messages: { en, km, zh, ko, ja },
})

/**
 * The single place that changes the active language — updates the i18n
 * instance, persists the choice, and keeps <html lang> in sync for
 * accessibility and search engines. Call this instead of writing to
 * `i18n.global.locale` directly.
 */
export function setLocale(locale: Locale): void {
  i18n.global.locale.value = locale
  localStorage.setItem(STORAGE_KEY, locale)
  document.documentElement.setAttribute('lang', locale)
}

// Sync <html lang> on initial load too, not just on later changes.
document.documentElement.setAttribute('lang', i18n.global.locale.value)
