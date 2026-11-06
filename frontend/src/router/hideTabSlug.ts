/** "/admin/payroll/tax" -> "hide-tab.payroll.tax": the mark Settings > Roles stores for a hidden tab (see tabVisibility.ts). */
export function hideTabSlug(to: string): string {
  return 'hide-tab.' + to.replace(/^\/admin\/?/, '').replace(/\//g, '.')
}
