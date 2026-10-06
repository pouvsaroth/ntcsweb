/**
 * Dropdown options for an optional Organization Management pick (branch,
 * department, team, ...) — a leading "None" entry, since BaseSelect's own
 * placeholder can't be re-selected to clear a value once one is chosen.
 */
export function optionalOptions(rows: { id: number; name: string }[], noneLabel: string): { value: string; label: string }[] {
  return [{ value: '', label: noneLabel }, ...rows.map((row) => ({ value: String(row.id), label: row.name }))]
}
