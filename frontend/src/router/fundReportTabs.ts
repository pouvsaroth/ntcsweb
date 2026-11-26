/**
 * The tabs of Fund > Reports, in display order — shared by the tab bar
 * (FundReportsLayout.vue), the `/admin/fund-reports` redirect to the first
 * tab this account can open, and Settings > Roles (a Show box per tab).
 */
export interface FundReportTab {
  to: string
  labelKey: string
  permission: string
}

export const fundReportTabs: FundReportTab[] = [
  { to: '/admin/fund-reports/summary', labelKey: 'admin.fundReports.tabs.summary', permission: 'funds.view' },
  { to: '/admin/fund-reports/money-in-out', labelKey: 'admin.fundReports.tabs.moneyInOut', permission: 'funds.view' },
  { to: '/admin/fund-reports/providers-by-month', labelKey: 'admin.fundReports.tabs.byMonth', permission: 'funds.view' },
]
