/**
 * The tabs of HRM > Organization Management, in display order — shared by
 * the tab bar (OrganizationManagement.vue), the `/admin/organization`
 * redirect to the first tab this account can open, and the sidebar link's
 * own permission check (adminNav.ts), so the three never disagree.
 */
export interface OrganizationTab {
  to: string
  labelKey: string
  /** Any one of these opens the tab. */
  permission: string | string[]
}

export const organizationTabs: OrganizationTab[] = [
  { to: '/admin/organization/school', labelKey: 'admin.organization.tabs.school', permission: 'tenant-settings.view' },
  { to: '/admin/organization/branches', labelKey: 'admin.organization.tabs.branch', permission: 'organization.view' },
  // Same departments the Assets menu manages (and still links to) — one list,
  // two ways in, so either permission opens it (see DepartmentPolicy).
  { to: '/admin/organization/departments', labelKey: 'admin.organization.tabs.department', permission: ['organization.view', 'assets.view'] },
  { to: '/admin/organization/teams', labelKey: 'admin.organization.tabs.team', permission: 'organization.view' },
  { to: '/admin/organization/positions', labelKey: 'admin.organization.tabs.position', permission: 'positions.view' },
  { to: '/admin/organization/job-grades', labelKey: 'admin.organization.tabs.jobGrade', permission: 'organization.view' },
  { to: '/admin/organization/job-levels', labelKey: 'admin.organization.tabs.jobLevel', permission: 'organization.view' },
  { to: '/admin/organization/reporting', labelKey: 'admin.organization.tabs.reportingManager', permission: 'organization.view' },
  { to: '/admin/organization/hierarchy', labelKey: 'admin.organization.tabs.hierarchy', permission: 'organization.view' },
]

export function canOpenOrganizationTab(tab: OrganizationTab, can: (permission: string) => boolean): boolean {
  return (Array.isArray(tab.permission) ? tab.permission : [tab.permission]).some(can)
}
