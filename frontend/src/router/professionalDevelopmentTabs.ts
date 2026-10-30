/**
 * The tabs of HRM > Professional Development
 * (docs/hrm/professional-development.md), in display order — shared by the
 * tab bar, the `/admin/professional-development` redirect and the sidebar
 * link's permission check. A tab shows when the user holds any of its
 * permissions: Classroom observation, Coaching and Development plan are also self-service
 * (my-requests.view), listing only what the person is in.
 */
export interface ProfessionalDevelopmentTab {
  to: string
  labelKey: string
  permissions: string[]
}

const VIEW = 'professional-development.view'
const SELF = 'my-requests.view'

export const professionalDevelopmentTabs: ProfessionalDevelopmentTab[] = [
  { to: '/admin/professional-development/records', labelKey: 'proDev.tabs.records', permissions: [VIEW] },
  { to: '/admin/professional-development/calendar', labelKey: 'proDev.tabs.calendar', permissions: [VIEW] },
  { to: '/admin/professional-development/certificates', labelKey: 'proDev.tabs.certificates', permissions: [VIEW] },
  { to: '/admin/professional-development/observations', labelKey: 'proDev.tabs.observations', permissions: [VIEW, SELF] },
  { to: '/admin/professional-development/coaching', labelKey: 'proDev.tabs.coaching', permissions: [VIEW, SELF] },
  { to: '/admin/professional-development/plans', labelKey: 'proDev.tabs.plans', permissions: [VIEW, SELF] },
  { to: '/admin/professional-development/reports', labelKey: 'proDev.tabs.reports', permissions: [VIEW] },
]

export function canOpenProfessionalDevelopmentTab(tab: ProfessionalDevelopmentTab, can: (permission: string) => boolean): boolean {
  return tab.permissions.some((p) => can(p))
}
