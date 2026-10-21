/**
 * The tabs of HRM > Teacher (docs/hrm/teachers.md), in display order — shared
 * by the tab bar (TeachersLayout.vue), the `/admin/teachers` redirect and the
 * sidebar link's permission check. Profile and employment are on Staff.
 */
export interface TeacherTab {
  to: string
  labelKey: string
  permission: string
}

export const teacherTabs: TeacherTab[] = [
  { to: '/admin/teachers/qualifications', labelKey: 'teacherMgmt.tabs.qualifications', permission: 'teachers.view' },
  { to: '/admin/teachers/assignments', labelKey: 'teacherMgmt.tabs.assignments', permission: 'teachers.view' },
  { to: '/admin/teachers/homeroom', labelKey: 'teacherMgmt.tabs.homeroom', permission: 'teachers.view' },
  { to: '/admin/teachers/load', labelKey: 'teacherMgmt.tabs.load', permission: 'teachers.view' },
  { to: '/admin/teachers/availability', labelKey: 'teacherMgmt.tabs.availability', permission: 'teachers.view' },
  { to: '/admin/teachers/substitutes', labelKey: 'teacherMgmt.tabs.substitutes', permission: 'teachers.view' },
  { to: '/admin/teachers/documents', labelKey: 'teacherMgmt.tabs.documents', permission: 'teachers.view' },
]
