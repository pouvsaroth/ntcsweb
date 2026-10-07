/**
 * The tabs of HRM > Performance Management, in display order — shared by the
 * tab bar (PerformanceLayout.vue), the `/admin/performance` redirect to the
 * first tab this account can open, and the sidebar link's permission check.
 * The two assessment tabs are self-service — any staff account
 * (my-requests.view, every staff role has it) sees them.
 */
export interface PerformanceTab {
  to: string
  labelKey: string
  permission: string
}

export const performanceTabs: PerformanceTab[] = [
  { to: '/admin/performance/kpis', labelKey: 'admin.performance.tabs.kpi', permission: 'performance.view' },
  { to: '/admin/performance/goals', labelKey: 'admin.performance.tabs.goals', permission: 'performance.view' },
  { to: '/admin/performance/reviews', labelKey: 'admin.performance.tabs.review', permission: 'performance.view' },
  { to: '/admin/performance/forms', labelKey: 'admin.performance.tabs.forms', permission: 'performance.view' },
  { to: '/admin/performance/self-assessment', labelKey: 'admin.performance.tabs.selfAssessment', permission: 'my-requests.view' },
  { to: '/admin/performance/manager-assessment', labelKey: 'admin.performance.tabs.managerAssessment', permission: 'my-requests.view' },
  { to: '/admin/performance/scores', labelKey: 'admin.performance.tabs.score', permission: 'performance.view' },
  { to: '/admin/performance/promotions', labelKey: 'admin.performance.tabs.promotion', permission: 'performance.view' },
]
