import { apiGet, apiPut } from '@/services/http'
import { applyTranslationOverrides, type Locale } from '@/i18n'

/**
 * The school's own wording of the app's text (Settings > Language >
 * Translation) — see TranslationOverrideController on the backend. Keys are
 * the dotted message keys of the locale files (adminNav.items.students).
 */
export type TranslationOverrides = Record<Locale, Record<string, string>>

export interface TranslationChange {
  locale: Locale
  key: string
  /** Empty removes the school's word, so the built-in text shows again. */
  value: string
}

export const translationOverridesService = {
  get: () => apiGet<TranslationOverrides>('/translation-overrides'),

  /** Saves the batch, applies the result at once, and returns it. */
  async save(changes: TranslationChange[]): Promise<TranslationOverrides> {
    const overrides = await apiPut<TranslationOverrides>('/translation-overrides', { changes })
    applyTranslationOverrides(overrides)
    return overrides
  },
}

/**
 * Called by AdminLayout once signed in (and again on switching school): lays
 * this school's words over the built-in text. Any failure — e.g. a Super
 * Admin outside every school — just leaves the built-in text.
 */
export async function loadTranslationOverrides(): Promise<void> {
  try {
    applyTranslationOverrides(await translationOverridesService.get())
  } catch {
    applyTranslationOverrides({})
  }
}
