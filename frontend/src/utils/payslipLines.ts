import type { PayslipLine } from '@/services/payroll'

/**
 * A payslip line's name in the app's language — the fixed lines (basic,
 * overtime, NSSF, tax, absence, a loan...) are translated; an allowance,
 * bonus or deduction keeps its own name, and an adjustment its note.
 */
export function payslipLineLabel(line: PayslipLine, t: (key: string) => string): string {
  switch (line.type) {
    case 'basic':
      return t('admin.payroll.lines.basic')
    case 'overtime':
      return t('admin.payroll.lines.overtime')
    case 'social_security':
      return t('admin.payroll.lines.nssf')
    case 'tax':
      return t('admin.payroll.lines.tax')
    case 'attendance':
      return t(`admin.payroll.lines.${line.code}`)
    case 'loan':
      return t(`admin.payroll.loans.type.${line.code}`)
    case 'adjustment':
      return line.name === 'adjustment' ? t('admin.payroll.lines.adjustment') : `${t('admin.payroll.lines.adjustment')}: ${line.name}`
    default:
      return line.name
  }
}
