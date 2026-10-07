/**
 * The tabs of HRM > Payroll, in display order — shared by the tab bar
 * (PayrollLayout.vue), the `/admin/payroll` redirect to the first tab this
 * account can open, and the sidebar link's permission check.
 */
export interface PayrollTab {
  to: string
  labelKey: string
  permission: string
}

export const payrollTabs: PayrollTab[] = [
  { to: '/admin/payroll/salary-structures', labelKey: 'admin.payroll.tabs.salaryStructures', permission: 'payroll.view' },
  { to: '/admin/payroll/basic-salary', labelKey: 'admin.payroll.tabs.basicSalary', permission: 'payroll.view' },
  { to: '/admin/payroll/allowances', labelKey: 'admin.payroll.tabs.allowances', permission: 'payroll.view' },
  { to: '/admin/payroll/bonuses', labelKey: 'admin.payroll.tabs.bonuses', permission: 'payroll.view' },
  { to: '/admin/payroll/deductions', labelKey: 'admin.payroll.tabs.deductions', permission: 'payroll.view' },
  { to: '/admin/payroll/overtime', labelKey: 'admin.payroll.tabs.overtime', permission: 'payroll.view' },
  { to: '/admin/payroll/attendance-deduction', labelKey: 'admin.payroll.tabs.attendanceDeduction', permission: 'payroll.view' },
  { to: '/admin/payroll/tax', labelKey: 'admin.payroll.tabs.tax', permission: 'payroll.view' },
  { to: '/admin/payroll/social-security', labelKey: 'admin.payroll.tabs.socialSecurity', permission: 'payroll.view' },
  { to: '/admin/payroll/loans', labelKey: 'admin.payroll.tabs.loans', permission: 'payroll.view' },
  { to: '/admin/payroll/calculation', labelKey: 'admin.payroll.tabs.calculation', permission: 'payroll.view' },
  { to: '/admin/payroll/payslips', labelKey: 'admin.payroll.tabs.payslip', permission: 'payroll.view' },
  { to: '/admin/payroll/approval', labelKey: 'admin.payroll.tabs.approval', permission: 'payroll.approve' },
  { to: '/admin/payroll/history', labelKey: 'admin.payroll.tabs.history', permission: 'payroll.view' },
]
