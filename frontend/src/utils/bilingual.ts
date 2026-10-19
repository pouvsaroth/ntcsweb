/**
 * Text saved in Khmer and English (`title_km` / `title_en`): the visitor's
 * language, else the other one, so a field filled in one language only
 * still shows. Every language but Khmer reads the English text.
 */
export function pickText(source: object, field: string, locale: string): string {
  const values = source as Record<string, unknown>
  const km = String(values[`${field}_km`] ?? '').trim()
  const en = String(values[`${field}_en`] ?? '').trim()

  return locale === 'km' ? km || en : en || km
}

/** A "one per line" text as its non-empty lines. */
export function lines(text: string): string[] {
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
}
