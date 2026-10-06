/**
 * The tabs of HRM > Leave Management, in display order — shared by the tab
 * bar (LeaveLayout.vue), the `/admin/leave-management` redirect to the first
 * tab this account can open, and the sidebar link's permission check.
 */
export interface LeaveTab {
  to: string
  labelKey: string
  permission: string
}

export const leaveTabs: LeaveTab[] = [
  { to: '/admin/leave-management/types', labelKey: 'admin.leaveManagement.tabs.types', permission: 'leave-management.view' },
  { to: '/admin/leave-management/policies', labelKey: 'admin.leaveManagement.tabs.policies', permission: 'leave-management.view' },
  { to: '/admin/leave-management/balances', labelKey: 'admin.leaveManagement.tabs.balances', permission: 'leave-management.view' },
  { to: '/admin/leave-management/requests', labelKey: 'admin.leaveManagement.tabs.requests', permission: 'leave-requests.view' },
  { to: '/admin/leave-management/workflow', labelKey: 'admin.leaveManagement.tabs.workflow', permission: 'leave-management.view' },
  { to: '/admin/leave-management/calendar', labelKey: 'admin.leaveManagement.tabs.calendar', permission: 'leave-management.view' },
  { to: '/admin/leave-management/carry-forward', labelKey: 'admin.leaveManagement.tabs.carryForward', permission: 'leave-management.view' },
  { to: '/admin/leave-management/reports', labelKey: 'admin.leaveManagement.tabs.reports', permission: 'leave-management.view' },
]
