import { i18n } from '@/i18n'

/**
 * A stored gender code ("male" / "female" / "other") in the language being
 * shown — e.g. "female" → "ស្រី" in Khmer. Reactive inside a template: it
 * re-renders when the language changes. Also accepts the odd legacy
 * spelling ("Male", "F"); anything else is shown as it was stored.
 */
export function genderLabel(value: string | null | undefined): string {
  if (!value) return '—'

  const code = value.trim().toLowerCase()
  const key = code === 'male' || code === 'm' ? 'genderMale' : code === 'female' || code === 'f' ? 'genderFemale' : code === 'other' ? 'genderOther' : null

  return key ? i18n.global.t(`common.${key}`) : value
}
