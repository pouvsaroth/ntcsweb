/**
 * The tabs of Front Office, in display order — shared by the tab bar
 * (FrontOfficeLayout.vue), the `/admin/front-office` redirect to the first
 * tab this account can open, and the sidebar link's permission check.
 */
export interface FrontOfficeTab {
  to: string
  labelKey: string
  permission: string
}

export const frontOfficeTabs: FrontOfficeTab[] = [
  { to: '/admin/front-office/inquiries', labelKey: 'frontOffice.tabs.inquiries', permission: 'inquiries.view' },
  { to: '/admin/front-office/meetings', labelKey: 'frontOffice.tabs.meetings', permission: 'meetings.view' },
  { to: '/admin/front-office/appointments', labelKey: 'frontOffice.tabs.appointments', permission: 'appointments.view' },
  { to: '/admin/front-office/visitors', labelKey: 'frontOffice.tabs.visitors', permission: 'visitors.view' },
]
