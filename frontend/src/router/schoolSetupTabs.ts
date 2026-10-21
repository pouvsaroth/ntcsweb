/**
 * The tabs of Academics > School setup (docs/academics/school-setup.md), in
 * display order — shared by the tab bar (SchoolSetupLayout.vue), the
 * `/admin/school-setup` redirect to the first tab this account can open, and
 * the sidebar link's permission check.
 */
export interface SchoolSetupTab {
  to: string
  labelKey: string
  permission: string
}

export const schoolSetupTabs: SchoolSetupTab[] = [
  { to: '/admin/school-setup/terms', labelKey: 'schoolSetup.tabs.terms', permission: 'academic-terms.view' },
  { to: '/admin/school-setup/grade-levels', labelKey: 'schoolSetup.tabs.gradeLevels', permission: 'grade-levels.view' },
  { to: '/admin/school-setup/curriculum', labelKey: 'schoolSetup.tabs.curriculum', permission: 'curriculum.view' },
  { to: '/admin/school-setup/calendar', labelKey: 'schoolSetup.tabs.calendar', permission: 'school-calendar.view' },
  { to: '/admin/school-setup/promotion-rules', labelKey: 'schoolSetup.tabs.promotionRules', permission: 'promotion-rules.view' },
  { to: '/admin/school-setup/promote', labelKey: 'schoolSetup.tabs.promote', permission: 'student-promotions.view' },
]
