import { organizationTabs } from '@/router/organizationTabs'
import { attendanceTabs } from '@/router/attendanceTabs'
import { schoolSetupTabs } from '@/router/schoolSetupTabs'
import { teacherTabs } from '@/router/teacherTabs'
import { timetableTabs } from '@/router/timetableTabs'
import { lessonTabs } from '@/router/lessonTabs'
import { leaveTabs } from '@/router/leaveTabs'
import { payrollTabs } from '@/router/payrollTabs'
import { performanceTabs } from '@/router/performanceTabs'
import { professionalDevelopmentTabs } from '@/router/professionalDevelopmentTabs'
import { documentLinks } from '@/router/publicNav'
import { examinationPermissions } from '@/router/examinationTabs'
import { frontOfficeTabs } from '@/router/frontOfficeTabs'
import { recruitmentTabs } from '@/router/recruitmentTabs'

export interface AdminNavItem {
  labelKey: string
  /** NavIcon name — shown beside the label, and alone on the collapsed sidebar. */
  icon?: string
  /** Omitted only for an external document link — see `urlKey`. */
  to?: string
  /**
   * An external file a school admin uploaded under School Documents (see
   * siteStore's `info.documents`) instead of an in-app route — AdminSidebar
   * renders this as a plain `<a target="_blank">` and hides the item
   * entirely if that document hasn't been uploaded.
   */
  urlKey?: 'school_regulation_url' | 'student_attendance_policy_url'
  /** Only shown to a platform Super Admin (tenant_id IS NULL). */
  superAdminOnly?: boolean
  /**
   * A permission slug required to see this item; omitted means "any
   * authenticated admin". An array means "any one of these" — e.g.
   * Approvals is visible to someone who can only view the leave-request
   * queue, not just someone with the generic approval-queue permission.
   */
  permission?: string | string[]
  /**
   * Only shown to the Student role — these self-service pages (My Scores,
   * My Attendance, ...) aren't gated by any permission at all (Student
   * holds none, see backend Permissions::rolePermissions()), so a
   * permission check would hide them from the one role they're actually
   * for.
   */
  studentOnly?: boolean
  /** Hidden from the Student role — e.g. an item their own flat menu already has. */
  hiddenForStudent?: boolean
  /**
   * Also shown to a member of any Approval Flow group, even without
   * `permission` — being in a flow's group is enough to approve its step
   * (see backend ApprovalFlow).
   */
  flowApprover?: boolean
  /**
   * The plan module this item belongs to (back office billing, see backend
   * Modules.php) — only for items no module permission already gates, such
   * as the self-service My check-in, which every staff member can open.
   */
  module?: string
}

export interface AdminNavGroup {
  labelKey: string
  /** NavIcon name for the group heading (the icon the collapsed sidebar shows). */
  icon?: string
  items: AdminNavItem[]
  /**
   * Rendered by AdminSidebar as plain top-level links with no collapsible
   * group heading (Dashboard, Project Management). The group itself stays in this list so
   * firstAccessibleAdminPath() and AdminHeader's breadcrumb lookup still
   * find its items.
   */
  standalone?: boolean
  /**
   * Rendered as a plain list of links with no group heading at all — the
   * Student role's own menu (see the first student group below).
   */
  flat?: boolean
}

/**
 * The full target shape of the admin panel (matches the platform spec), even
 * though most of these routes currently render a "coming soon" placeholder —
 * see src/pages/admin/ComingSoon.vue. Each one gets wired up to a real page
 * as its backend phase lands (Phase 5 academics, Phase 6 admin API, Phase 9
 * website content), with no change needed here.
 *
 * `labelKey`/`groupKey` are i18n message keys, resolved by consumers with
 * useI18n()'s t() — never hardcode a display label here.
 */
export const adminNav: AdminNavGroup[] = [
  {
    labelKey: 'adminNav.groups.overview',
    icon: 'home',
    standalone: true,
    items: [{ labelKey: 'adminNav.items.dashboard', icon: 'home', to: '/admin', permission: 'dashboard.view' }],
  },
  {
    // A student's own sidebar — no heading, and the same menu, in the same
    // order, as the student card grid on Dashboard.vue
    // (studentQuickAccessItems), then the school's uploaded documents. Exam
    // Application is reached from the My Request page's buttons.
    labelKey: 'adminNav.groups.myProfile',
    icon: 'userCircle',
    flat: true,
    items: [
      // No `permission` here on purpose: the Overview group's own Dashboard
      // item above requires dashboard.view, which the Student role
      // deliberately never holds (see Permissions::defaultsForSystemRoles())
      // — this is the only way back to their home page (Dashboard.vue's
      // student card grid) once they've navigated away from it.
      { labelKey: 'adminNav.items.dashboard', icon: 'home', to: '/admin', studentOnly: true },
      { labelKey: 'studentNav.score', icon: 'chartBar', to: '/admin/my-scores', studentOnly: true },
      { labelKey: 'studentNav.attendant', icon: 'checkCircle', to: '/admin/my-attendance', studentOnly: true },
      { labelKey: 'studentNav.video', icon: 'video', to: '/admin/my-videos', studentOnly: true },
      { labelKey: 'studentNav.myRequest', icon: 'annotation', to: '/admin/my-feedback', studentOnly: true },
      { labelKey: 'adminNav.items.myRequests', icon: 'inbox', to: '/admin/approvals/my-requests', studentOnly: true },
      { labelKey: 'adminNav.items.notifications', icon: 'bell', to: '/admin/notifications', studentOnly: true },
      ...documentLinks.map((item) => ({ ...item, studentOnly: true })),
    ],
  },
  {
    // One top-level link; Projects is a tab on the page itself (see
    // ProjectsTabs.vue), ready for more Project Management tabs later.
    labelKey: 'adminNav.groups.projectManagement',
    icon: 'collection',
    standalone: true,
    items: [{ labelKey: 'adminNav.groups.projectManagement', icon: 'collection', to: '/admin/projects', permission: 'projects.view' }],
  },
  {
    // One top-level link; Inquiry, Meetings, Appointments and Visitors
    // are tabs on the page itself (see frontOfficeTabs.ts).
    labelKey: 'adminNav.groups.frontOffice',
    icon: 'inbox',
    standalone: true,
    items: [{ labelKey: 'adminNav.groups.frontOffice', icon: 'inbox', to: '/admin/front-office', permission: frontOfficeTabs.map((tab) => tab.permission) }],
  },
  {
    labelKey: 'adminNav.groups.platform',
    icon: 'globe',
    items: [
      { labelKey: 'adminNav.items.tenants', icon: 'globe', to: '/admin/tenants', superAdminOnly: true },
    ],
  },
  {
    labelKey: 'adminNav.groups.academic',
    icon: 'academic',
    items: [
      // Academic Years, Course, Books, Book Categories, and Study Mode used
      // to each be their own sidebar entry — they're now reached as tabs
      // from this one "Programs" item (see ProgramsTabs.vue), landing on
      // the Academic Programs list itself.
      { labelKey: 'adminNav.items.programs', icon: 'bookOpen', to: '/admin/academic-programs', permission: 'academic-programs.view' },
      // Terms, grade levels, curriculum, school calendar, promotion rules, promoting students (SchoolSetupLayout.vue).
      { labelKey: 'adminNav.items.schoolSetup', icon: 'adjustments', to: '/admin/school-setup', permission: schoolSetupTabs.map((tab) => tab.permission) },
      { labelKey: 'adminNav.items.videos', icon: 'video', to: '/admin/videos', permission: 'videos.view' },
      // Buildings and Classrooms used to be separate sidebar entries — now
      // reached as tabs from this one "Study Building" item (see
      // StudyBuildingTabs.vue), landing on the Buildings list itself.
      {
        labelKey: 'adminNav.items.studyBuilding',
        to: '/admin/buildings',
        permission: ['buildings.view', 'classrooms.view'],
      },
      { labelKey: 'adminNav.items.classes', icon: 'userGroup', to: '/admin/classes', permission: 'classes.view' },
      // Class / teacher / room timetables, subject hours, conflicts, schedule changes (timetableTabs.ts).
      { labelKey: 'adminNav.items.timetable', icon: 'calendar', to: '/admin/timetable', permission: timetableTabs.map((tab) => tab.permission) },
      // Plans, week, teaching log, review, curriculum progress, delays (lessonTabs.ts).
      { labelKey: 'adminNav.items.lessonPlans', icon: 'document', to: '/admin/lessons', permission: [...new Set(lessonTabs.flatMap((tab) => tab.permission))] },
      { labelKey: 'adminNav.items.attendance', icon: 'checkCircle', to: '/admin/attendance', permission: 'attendance.view' },
      // Exams and Grades used to be separate sidebar entries under Academic
      // Records — now reached as tabs from this one "Examination" item (see
      // ExaminationTabs.vue), landing on the Exams page itself. Still an
      // unbuilt "coming soon" placeholder (see ComingSoon.vue) — this
      // permission exists purely to gate sidebar visibility until a real
      // feature (and its own permission) lands behind it.
      { labelKey: 'adminNav.items.examination', icon: 'pencil', to: '/admin/examination', permission: ['examination.view', ...examinationPermissions] },
    ],
  },
  {
    labelKey: 'adminNav.groups.students',
    icon: 'userGroup',
    items: [
      { labelKey: 'adminNav.items.studentsList', icon: 'users', to: '/admin/students', permission: 'students.view' },
      { labelKey: 'adminNav.items.studentImports', icon: 'upload', to: '/admin/student-imports', permission: 'students.create' },
      { labelKey: 'adminNav.items.enrollments', icon: 'clipboardList', to: '/admin/enrollments', permission: 'enrollments.view' },
    ],
  },
  {
    labelKey: 'adminNav.groups.staff',
    icon: 'briefcase',
    items: [
      { labelKey: 'adminNav.items.staffList', icon: 'user', to: '/admin/staff', permission: 'staff.view' },
      // Staff in a teacher position: qualifications, assignments, homeroom, load, ... (teacherTabs.ts).
      { labelKey: 'adminNav.items.teachers', icon: 'academic', to: '/admin/teachers', permission: teacherTabs.map((tab) => tab.permission), module: 'hrm' },
      { labelKey: 'adminNav.items.staffStatusHistory', icon: 'clock', to: '/admin/staff-status-history', permission: 'staff.view' },
      // One link; School, Branch, Department, ... are tabs on the page itself
      // (see organizationTabs.ts). Shown to anyone who can open any one tab.
      { labelKey: 'adminNav.items.organizationManagement', icon: 'template', to: '/admin/organization', permission: organizationTabs.flatMap((tab) => tab.permission) },
      // One link; Manpower request, Job positions, ... are tabs on the page itself (see recruitmentTabs.ts).
      { labelKey: 'adminNav.items.recruitment', icon: 'userAdd', to: '/admin/recruitment', permission: recruitmentTabs.map((tab) => tab.permission) },
      // One link; Shift, Work schedule, Holidays, ... are tabs on the page itself (see attendanceTabs.ts).
      { labelKey: 'adminNav.items.timeAttendance', icon: 'clock', to: '/admin/time-attendance', permission: attendanceTabs.map((tab) => tab.permission) },
      // One link; Leave types, Leave policies, ... are tabs on the page itself (see leaveTabs.ts).
      { labelKey: 'adminNav.items.leaveManagement', icon: 'calendar', to: '/admin/leave-management', permission: leaveTabs.map((tab) => tab.permission) },
      // One link; Salary structure, Basic salary, ... are tabs on the page itself (see payrollTabs.ts).
      { labelKey: 'adminNav.items.payroll', icon: 'cash', to: '/admin/payroll', permission: payrollTabs.map((tab) => tab.permission) },
      // One link; KPI, Goals, Performance review, ... are tabs on the page itself (see performanceTabs.ts).
      { labelKey: 'adminNav.items.performance', icon: 'star', to: '/admin/performance', permission: performanceTabs.map((tab) => tab.permission), module: 'hrm' },
      // One link; Training records, Training calendar, Certificates, ... are tabs on the page itself (see professionalDevelopmentTabs.ts).
      { labelKey: 'adminNav.items.professionalDevelopment', icon: 'academic', to: '/admin/professional-development', permission: [...new Set(professionalDevelopmentTabs.flatMap((tab) => tab.permissions))], module: 'hrm' },
      // Self-service for every staff account — the same slug as Request Leave
      // below, so students (who hold none) never see it. See MyCheckIn.vue.
      { labelKey: 'adminNav.items.myCheckIn', icon: 'location', to: '/admin/my-check-in', permission: 'my-requests.view', module: 'hrm' },
      // Self-service, same page used by the public site's "Document and
      // Form" menu for students — MyRequests.vue's backend auto-detects
      // whether the signed-in account is a student or staff (see
      // MyLeaveRequestController::requesterOrFail()). my-requests.view is
      // granted to every role by default (see
      // Permissions::$selfServiceForEveryone), so this shows for any staff
      // account, not just those with staff-management permissions.
      // Both items land on the same page — it shows an "Ask for Permission"
      // block and a "Resignation" block side by side (see MyRequests.vue),
      // rather than auto-opening one specific modal, so a visitor without a
      // linked staff record (e.g. a school admin) isn't immediately shown a
      // failing popup for whichever one they didn't mean to open. The
      // `?tab=resignation` on the second link only exists so the two sidebar
      // entries don't share an identical `to` (which the sidebar keys and
      // highlights by) — MyRequests.vue never reads it.
      { labelKey: 'adminNav.items.requestLeave', icon: 'calendar', to: '/admin/approvals/my-requests', permission: 'my-requests.view', module: 'hrm' },
      { labelKey: 'adminNav.items.resignationForm', icon: 'logout', to: '/admin/approvals/my-requests?tab=resignation', permission: 'my-requests.view' },
    ],
  },
  {
    labelKey: 'adminNav.groups.billing',
    icon: 'creditCard',
    items: [
      { labelKey: 'adminNav.items.billingDashboard', icon: 'chartBar', to: '/admin/billing', permission: 'billing-reports.view' },
      { labelKey: 'adminNav.items.products', icon: 'tag', to: '/admin/products', permission: 'products.view' },
      { labelKey: 'adminNav.items.invoices', icon: 'document', to: '/admin/invoices', permission: 'invoices.view' },
      { labelKey: 'adminNav.items.payments', icon: 'dollar', to: '/admin/payments', permission: 'payments.view' },
      { labelKey: 'adminNav.items.currencyRates', icon: 'switchHorizontal', to: '/admin/currency-rates', permission: 'currency-rates.view' },
    ],
  },
  {
    labelKey: 'adminNav.groups.accounting',
    icon: 'calculator',
    items: [
      { labelKey: 'adminNav.items.accountingDashboard', icon: 'chartBar', to: '/admin/accounting', permission: 'accounting.dashboard.view' },
      { labelKey: 'adminNav.items.accounts', icon: 'library', to: '/admin/accounts', permission: 'accounts.view' },
      { labelKey: 'adminNav.items.income', icon: 'trendingUp', to: '/admin/income', permission: 'income.view' },
      { labelKey: 'adminNav.items.expenses', icon: 'trendingDown', to: '/admin/expenses', permission: 'expense.view' },
      { labelKey: 'adminNav.items.transactions', icon: 'switchVertical', to: '/admin/transactions', permission: 'transactions.view' },
      { labelKey: 'adminNav.items.accountingReports', icon: 'report', to: '/admin/accounting-reports', permission: 'reports.financial.view' },
    ],
  },
  {
    labelKey: 'adminNav.groups.assets',
    icon: 'cube',
    items: [
      { labelKey: 'adminNav.items.assetDashboard', icon: 'chartBar', to: '/admin/assets/dashboard', permission: 'assets.reports.view' },
      { labelKey: 'adminNav.items.assets', icon: 'desktop', to: '/admin/assets', permission: 'assets.view' },
      // Self-service ("assets assigned to me") at the API layer — see
      // MyAssetController — but still permission-gated here for sidebar
      // visibility, same as every other item.
      { labelKey: 'adminNav.items.myAssets', icon: 'user', to: '/admin/my-assets', permission: 'my-assets.view' },
      { labelKey: 'adminNav.items.assetIssues', icon: 'exclamation', to: '/admin/asset-issues', permission: 'assets.issue.view' },
      { labelKey: 'adminNav.items.assetRepairs', icon: 'cog', to: '/admin/asset-repairs', permission: 'assets.repair.view' },
      { labelKey: 'adminNav.items.repairShops', icon: 'building', to: '/admin/repair-shops', permission: 'assets.repair.view' },
      { labelKey: 'adminNav.items.assetMaintenance', icon: 'refresh', to: '/admin/asset-maintenance', permission: 'assets.maintenance.view' },
      { labelKey: 'adminNav.items.assetCategories', icon: 'collection', to: '/admin/asset-categories', permission: 'assets.view' },
      { labelKey: 'adminNav.items.assetLocations', icon: 'location', to: '/admin/asset-locations', permission: 'assets.view' },
      { labelKey: 'adminNav.items.departments', icon: 'template', to: '/admin/departments', permission: 'assets.view' },
      { labelKey: 'adminNav.items.suppliers', icon: 'truck', to: '/admin/suppliers', permission: 'assets.view' },
      { labelKey: 'adminNav.items.assetReports', icon: 'report', to: '/admin/asset-reports', permission: 'assets.reports.view' },
    ],
  },
  // No Website group: the public website is edited in the back office
  // (docs/backoffice/stage-7-website.md).
  {
    labelKey: 'adminNav.groups.communication',
    icon: 'chat',
    items: [
      // Also still "coming soon" placeholders — see the Website group above.
      { labelKey: 'adminNav.items.contactMessages', icon: 'mail', to: '/admin/contact-messages', permission: 'contact-messages.view' },
      // Self-service — every signed-in account has its own notifications
      // (see the bell in AdminHeader.vue), not gated behind a permission.
      // (A student has it in their own flat menu instead, so this whole
      // Communication group disappears for them.)
      { labelKey: 'adminNav.items.notifications', icon: 'bell', to: '/admin/notifications', hiddenForStudent: true },
      { labelKey: 'adminNav.items.studentFeedback', icon: 'annotation', to: '/admin/student-feedback', permission: 'student-feedback.view' },
    ],
  },
  {
    labelKey: 'adminNav.groups.settings',
    icon: 'cog',
    items: [
      // School regulation and student attendance policy: an Academics item (stage 9d).
      { labelKey: 'adminNav.items.schoolDocuments', icon: 'folder', to: '/admin/school-documents', permission: 'tenant-settings.view', module: 'academics' },
      { labelKey: 'adminNav.items.settings', icon: 'cog', to: '/admin/settings', permission: 'tenant-settings.view' },
      // Bell notifications to Telegram groups (stage 11).
      { labelKey: 'adminNav.items.telegramGroups', icon: 'send', to: '/admin/telegram-notifications', permission: 'tenant-settings.update' },
      { labelKey: 'adminNav.items.users', icon: 'users', to: '/admin/users', permission: 'users.view' },
      { labelKey: 'adminNav.items.roles', icon: 'key', to: '/admin/roles', permission: 'roles.view' },
      { labelKey: 'adminNav.items.auditLogs', icon: 'shield', to: '/admin/audit-logs', permission: 'audit-logs.view' },
      { labelKey: 'adminNav.items.languages', icon: 'translate', to: '/admin/languages', permission: ['base-data.manage-languages', 'base-data.manage-translations'] },
      { labelKey: 'adminNav.items.lookupCategories', icon: 'list', to: '/admin/lookup-categories', permission: 'base-data.view' },
      // Super Admin: any database. database-backups.download (school-admin by
      // default): only their own school's — see DatabaseBackupController.
      // Opens on the Backup tab.
      { labelKey: 'adminNav.items.backup', icon: 'database', to: '/admin/database-backups', permission: 'database-backups.download' },
    ],
  },
  {
    // Forms and My Request are identity-gated at the API layer (submit/view
    // your own), same as LeaveRequest submission — but still permission-
    // gated here for sidebar visibility, same as every other item.
    labelKey: 'adminNav.groups.eApprovals',
    icon: 'clipboardCheck',
    items: [
      { labelKey: 'adminNav.items.forms', icon: 'document', to: '/admin/approvals/forms', permission: 'forms.view' },
      { labelKey: 'adminNav.items.myRequests', icon: 'inbox', to: '/admin/approvals/my-requests', permission: 'my-requests.view' },
      // Pending student self-registrations are decided here too (they used
      // to have their own Students > Registrations page).
      { labelKey: 'adminNav.items.approvals', icon: 'clipboardCheck', to: '/admin/approvals/queue', permission: ['approval-requests.view', 'leave-requests.view', 'make-up-class-requests.view', 'class-change-requests.view', 'students.approve-registration', 'gradebook.approve'], flowApprover: true },
      { labelKey: 'adminNav.items.formCategories', icon: 'folder', to: '/admin/form-categories', permission: 'form-categories.manage' },
      { labelKey: 'adminNav.items.formTemplates', icon: 'duplicate', to: '/admin/form-templates', permission: 'form-templates.manage' },
    ],
  },
  {
    // Who approves what: groups of users now, the flows built from them next.
    labelKey: 'adminNav.groups.approvalFlow',
    icon: 'share',
    items: [
      { labelKey: 'adminNav.items.approvalGroups', icon: 'userGroup', to: '/admin/approval-flow/groups', permission: 'approval-groups.manage' },
      { labelKey: 'adminNav.items.flowSetting', icon: 'share', to: '/admin/approval-flow/settings', permission: 'approval-groups.manage' },
    ],
  },
]

export interface AdminNavAccess {
  isSuperAdmin: boolean
  can: (permission: string) => boolean
  hasRole: (...slugs: string[]) => boolean
  isFlowApprover?: () => boolean
  hasModule?: (code: string) => boolean
}

/** The same visibility rule AdminSidebar.vue applies per item — shared so the router guard's "can this account even reach this page" check can't drift from what the sidebar actually shows. */
export function isNavItemVisible(item: AdminNavItem, access: AdminNavAccess): boolean {
  if (item.superAdminOnly && !access.isSuperAdmin) return false
  if (item.studentOnly && !access.hasRole('student')) return false
  if (item.hiddenForStudent && access.hasRole('student')) return false
  if (item.module && access.hasModule && !access.hasModule(item.module)) return false
  if (item.permission) {
    const required = Array.isArray(item.permission) ? item.permission : [item.permission]
    const viaFlow = item.flowApprover === true && access.isFlowApprover?.() === true
    if (!viaFlow && !required.some((permission) => access.can(permission))) return false
  }
  return true
}

/** The first sidebar destination this account can actually reach, in nav order — used to send someone who lacks the permission for the page they landed on (e.g. Dashboard) somewhere real instead of a page with nothing they're allowed to see. Null if the role can reach nothing in the sidebar at all. */
export function firstAccessibleAdminPath(access: AdminNavAccess): string | null {
  for (const group of adminNav) {
    for (const item of group.items) {
      // An external document link (`urlKey`, no `to`) is never a valid
      // redirect target — skip past it to the next real route.
      if (item.to && isNavItemVisible(item, access)) return item.to
    }
  }
  return null
}
