import type { TableColumn } from './api'

/**
 * Data ops > Tables: dates are shown and typed as dd-mm-yyyy (with any time
 * after it kept as is), and sent to the database as yyyy-mm-dd.
 */
export function isDateType(type: string): boolean {
  return type === 'date' || type.startsWith('timestamp')
}

/** "2026-11-11 09:30:00" → "11-11-2026 09:30:00" for a date column; anything else unchanged. */
export function toDisplay(column: TableColumn | undefined, value: string | null): string | null {
  if (value === null || !column || !isDateType(column.type)) return value
  const m = /^(\d{4})-(\d{2})-(\d{2})(.*)$/.exec(value)
  return m ? `${m[3]}-${m[2]}-${m[1]}${m[4]}` : value
}

/** "11-11-2026 09:30" → "2026-11-11 09:30" for a date column; anything else unchanged. */
export function toDb(column: TableColumn | undefined, value: string | null): string | null {
  if (value === null || !column || !isDateType(column.type)) return value
  const m = /^\s*(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})(.*)$/.exec(value)
  return m ? `${m[3]}-${m[2]!.padStart(2, '0')}-${m[1]!.padStart(2, '0')}${m[4]}` : value
}
