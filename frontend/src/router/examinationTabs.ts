/**
 * The tabs of Academic > Examination (docs/academics/gradebook.md), in display
 * order: the gradebook's tabs, then the Computer School's exam tabs. Shared by
 * the header tab bar (ExaminationTabs.vue), the `/admin/examination` redirect
 * to the first tab this account can open, and the sidebar link's check.
 *
 * `permission` is any-of.
 */
export interface ExaminationTab {
  to: string
  labelKey: string
  permission: string[]
}

const GRADEBOOK = ['gradebook.view', 'gradebook.enter', 'gradebook.manage-all', 'gradebook.approve']

export const examinationTabs: ExaminationTab[] = [
  { to: '/admin/examination/setup', labelKey: 'gradebook.tabs.setup', permission: ['assessment.view'] },
  { to: '/admin/examination/schedule', labelKey: 'gradebook.tabs.schedule', permission: ['exam-schedules.view'] },
  { to: '/admin/examination/entry', labelKey: 'gradebook.tabs.entry', permission: GRADEBOOK },
  { to: '/admin/examination/validation', labelKey: 'gradebook.tabs.validation', permission: GRADEBOOK },
  { to: '/admin/examination/calculation', labelKey: 'gradebook.tabs.calculation', permission: GRADEBOOK },
  { to: '/admin/examination/approval', labelKey: 'gradebook.tabs.approval', permission: GRADEBOOK },
  { to: '/admin/examination/corrections', labelKey: 'gradebook.tabs.corrections', permission: GRADEBOOK },
  { to: '/admin/exams', labelKey: 'adminNav.items.exams', permission: ['exam-applications.view'] },
  // Grades needs its own exam-scores.* permission, distinct from exam-applications.view.
  { to: '/admin/grades', labelKey: 'adminNav.items.grades', permission: ['exam-scores.view', 'exam-scores.manage-all'] },
  // Make-up applications and certificates are exam applications: the same permission as Exams.
  { to: '/admin/exams/make-up', labelKey: 'adminNav.items.makeUpExam', permission: ['exam-applications.view'] },
  { to: '/admin/exams/certificate', labelKey: 'adminNav.items.certificate', permission: ['exam-applications.view'] },
]

export const examinationPermissions = [...new Set(examinationTabs.flatMap((tab) => tab.permission))]
