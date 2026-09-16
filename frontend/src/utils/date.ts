/**
 * The one place every user-facing date is formatted — dd-mm-yyyy (e.g.
 * "23-08-2026"), the house format across the whole app, replacing what used
 * to be a mix of `toLocaleDateString()` (browser-locale-dependent) and raw
 * ISO strings printed verbatim.
 */

function pad(value: number): string {
  return String(value).padStart(2, '0')
}

/**
 * A pure "YYYY-MM-DD" date (no time component, as every backend Resource
 * sends via `toDateString()`) is parsed as UTC midnight by `new
 * Date(string)`, which can then render as the previous/next day once
 * displayed in the viewer's local timezone. Built from local year/month/day
 * instead so a date-only value never shifts. A full ISO datetime (carries
 * its own time + timezone offset, e.g. `created_at`) is left to the normal
 * parser, since it represents a real instant that should convert to local
 * time.
 */
function toDate(value: string | Date | null | undefined): Date | null {
  if (!value) return null
  if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value

  const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (dateOnly) {
    const [, year, month, day] = dateOnly
    return new Date(Number(year), Number(month) - 1, Number(day))
  }

  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? null : parsed
}

/** dd-mm-yyyy. Returns '—' for a null/undefined/invalid value, so a call site never needs its own ternary. */
export function formatDate(value: string | Date | null | undefined): string {
  const date = toDate(value)
  if (!date) return '—'
  return `${pad(date.getDate())}-${pad(date.getMonth() + 1)}-${date.getFullYear()}`
}

/** dd-mm-yyyy HH:mm, in the viewer's local time — for a timestamp (created_at, decided_at, ...), not a plain date. */
export function formatDateTime(value: string | Date | null | undefined): string {
  const date = toDate(value)
  if (!date) return '—'
  return `${formatDate(date)} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}
