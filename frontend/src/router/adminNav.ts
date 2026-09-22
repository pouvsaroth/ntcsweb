import { documentLinks, documentsAndFormLinks } from '@/router/publicNav'

export interface AdminNavItem {
  labelKey: string
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
}

export interface AdminNavGroup {
  labelKey: string
  items: AdminNavItem[]
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
    items: [{ labelKey: 'adminNav.items.dashboard', to: '/admin', permission: 'dashboard.view' }],
  },
  {
    // A student's own sidebar — same pages that used to live only in
    // PublicUserMenu.vue's dropdown (and StudentBottomNav's mobile tab
    // bar). Reusing publicNav.ts's documentsAndFormLinks for the last
    // three keeps that one array as the single source for their
    // labels/routes.
    labelKey: 'adminNav.groups.myProfile',
    items: [
      // No `permission` here on purpose: the Overview group's own Dashboard
      // item above requires dashboard.view, which the Student role
      // deliberately never holds (see Permissions::defaultsForSystemRoles())
      // — this is the only way back to their home page (Dashboard.vue's
      // student card grid) once they've navigated away from it.
      { labelKey: 'adminNav.items.dashboard', to: '/admin', studentOnly: true },
      { labelKey: 'studentNav.score', to: '/admin/my-scores', studentOnly: true },
      { labelKey: 'studentNav.attendant', to: '/admin/my-attendance', studentOnly: true },
      { labelKey: 'studentNav.video', to: '/admin/my-videos', studentOnly: true },
      ...documentsAndFormLinks.map((item) => ({ ...item, studentOnly: true })),
      ...documentLinks.map((item) => ({ ...item, studentOnly: true })),
    ],
  },
  {
    labelKey: 'adminNav.groups.projectManagement',
    items: [{ labelKey: 'adminNav.items.projects', to: '/admin/projects', permission: 'projects.view' }],
  },
  {
    labelKey: 'adminNav.groups.platform',
    items: [
      { labelKey: 'adminNav.items.tenants', to: '/admin/tenants', superAdminOnly: true },
      { labelKey: 'adminNav.items.databaseBackups', to: '/admin/database-backups', superAdminOnly: true },
    ],
  },
  {
    labelKey: 'adminNav.groups.academic',
    items: [
      // Academic Years, Course, Books, Book Categories, and Study Mode used
      // to each be their own sidebar entry — they're now reached as tabs
      // from this one "Programs" item (see ProgramsTabs.vue), landing on
      // the Academic Programs list itself.
      { labelKey: 'adminNav.items.programs', to: '/admin/academic-programs', permission: 'academic-programs.view' },
      { labelKey: 'adminNav.items.videos', to: '/admin/videos', permission: 'videos.view' },
      // Buildings and Classrooms used to be separate sidebar entries — now
      // reached as tabs from this one "Study Building" item (see
      // StudyBuildingTabs.vue), landing on the Buildings list itself.
      {
        labelKey: 'adminNav.items.studyBuilding',
        to: '/admin/buildings',
        permission: ['buildings.view', 'classrooms.view'],
      },
      { labelKey: 'adminNav.items.classes', to: '/admin/classes', permission: 'classes.view' },
      { labelKey: 'adminNav.items.attendance', to: '/admin/attendance', permission: 'attendance.view' },
      // Exams and Grades used to be separate sidebar entries under Academic
      // Records — now reached as tabs from this one "Examination" item (see
      // ExaminationTabs.vue), landing on the Exams page itself. Still an
      // unbuilt "coming soon" placeholder (see ComingSoon.vue) — this
      // permission exists purely to gate sidebar visibility until a real
      // feature (and its own permission) lands behind it.
      { labelKey: 'adminNav.items.examination', to: '/admin/exams', permission: 'examination.view' },
    ],
  },
  {
    labelKey: 'adminNav.groups.students',
    items: [
      { labelKey: 'adminNav.items.studentsList', to: '/admin/students', permission: 'students.view' },
      { labelKey: 'adminNav.items.studentRegistrations', to: '/admin/student-registrations', permission: 'students.approve-registration' },
      { labelKey: 'adminNav.items.studentImports', to: '/admin/student-imports', permission: 'students.create' },
      { labelKey: 'adminNav.items.enrollments', to: '/admin/enrollments', permission: 'enrollments.view' },
    ],
  },
  {
    labelKey: 'adminNav.groups.staff',
    items: [
      { labelKey: 'adminNav.items.staffList', to: '/admin/staff', permission: 'staff.view' },
      { labelKey: 'adminNav.items.positions', to: '/admin/positions', permission: 'positions.view' },
      { labelKey: 'adminNav.items.staffStatusHistory', to: '/admin/staff-status-history', permission: 'staff.view' },
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
      { labelKey: 'adminNav.items.requestLeave', to: '/admin/approvals/my-requests', permission: 'my-requests.view' },
      { labelKey: 'adminNav.items.resignationForm', to: '/admin/approvals/my-requests?tab=resignation', permission: 'my-requests.view' },
    ],
  },
  {
    labelKey: 'adminNav.groups.billing',
    items: [
      { labelKey: 'adminNav.items.billingDashboard', to: '/admin/billing', permission: 'billing-reports.view' },
      { labelKey: 'adminNav.items.products', to: '/admin/products', permission: 'products.view' },
      { labelKey: 'adminNav.items.invoices', to: '/admin/invoices', permission: 'invoices.view' },
      { labelKey: 'adminNav.items.payments', to: '/admin/payments', permission: 'payments.view' },
      { labelKey: 'adminNav.items.currencyRates', to: '/admin/currency-rates', permission: 'currency-rates.view' },
    ],
  },
  {
    labelKey: 'adminNav.groups.accounting',
    items: [
      { labelKey: 'adminNav.items.accountingDashboard', to: '/admin/accounting', permission: 'accounting.dashboard.view' },
      { labelKey: 'adminNav.items.accounts', to: '/admin/accounts', permission: 'accounts.view' },
      { labelKey: 'adminNav.items.income', to: '/admin/income', permission: 'income.view' },
      { labelKey: 'adminNav.items.expenses', to: '/admin/expenses', permission: 'expense.view' },
      { labelKey: 'adminNav.items.transactions', to: '/admin/transactions', permission: 'transactions.view' },
      { labelKey: 'adminNav.items.accountingReports', to: '/admin/accounting-reports', permission: 'reports.financial.view' },
    ],
  },
  {
    labelKey: 'adminNav.groups.assets',
    items: [
      { labelKey: 'adminNav.items.assetDashboard', to: '/admin/assets/dashboard', permission: 'assets.reports.view' },
      { labelKey: 'adminNav.items.assets', to: '/admin/assets', permission: 'assets.view' },
      // Self-service ("assets assigned to me") at the API layer — see
      // MyAssetController — but still permission-gated here for sidebar
      // visibility, same as every other item.
      { labelKey: 'adminNav.items.myAssets', to: '/admin/my-assets', permission: 'my-assets.view' },
      { labelKey: 'adminNav.items.assetIssues', to: '/admin/asset-issues', permission: 'assets.issue.view' },
      { labelKey: 'adminNav.items.assetRepairs', to: '/admin/asset-repairs', permission: 'assets.repair.view' },
      { labelKey: 'adminNav.items.repairShops', to: '/admin/repair-shops', permission: 'assets.repair.view' },
      { labelKey: 'adminNav.items.assetMaintenance', to: '/admin/asset-maintenance', permission: 'assets.maintenance.view' },
      { labelKey: 'adminNav.items.assetCategories', to: '/admin/asset-categories', permission: 'assets.view' },
      { labelKey: 'adminNav.items.assetLocations', to: '/admin/asset-locations', permission: 'assets.view' },
      { labelKey: 'adminNav.items.departments', to: '/admin/departments', permission: 'assets.view' },
      { labelKey: 'adminNav.items.suppliers', to: '/admin/suppliers', permission: 'assets.view' },
      { labelKey: 'adminNav.items.assetReports', to: '/admin/asset-reports', permission: 'assets.reports.view' },
    ],
  },
  {
    labelKey: 'adminNav.groups.website',
    items: [
      { labelKey: 'adminNav.items.homeSlides', to: '/admin/home-slides', permission: 'home-slides.view' },
      { labelKey: 'adminNav.items.aboutPage', to: '/admin/about-page', permission: 'tenant-settings.view' },
      // News/Events/Announcements/Documents are still "coming soon"
      // placeholders (see ComingSoon.vue) — these permissions exist purely
      // to gate sidebar visibility until real features land behind them.
      { labelKey: 'adminNav.items.news', to: '/admin/news', permission: 'news.view' },
      { labelKey: 'adminNav.items.events', to: '/admin/events', permission: 'events.view' },
      { labelKey: 'adminNav.items.announcements', to: '/admin/announcements', permission: 'announcements.view' },
      { labelKey: 'adminNav.items.gallery', to: '/admin/gallery', permission: 'gallery.view' },
      { labelKey: 'adminNav.items.promotions', to: '/admin/promotions', permission: 'promotions.view' },
      { labelKey: 'adminNav.items.documents', to: '/admin/documents', permission: 'documents.view' },
    ],
  },
  {
    labelKey: 'adminNav.groups.communication',
    items: [
      // Also still "coming soon" placeholders — see the Website group above.
      { labelKey: 'adminNav.items.contactMessages', to: '/admin/contact-messages', permission: 'contact-messages.view' },
      // Self-service — every signed-in account has its own notifications
      // (see the bell in AdminHeader.vue), not gated behind a permission.
      { labelKey: 'adminNav.items.notifications', to: '/admin/notifications' },
      { labelKey: 'adminNav.items.studentFeedback', to: '/admin/student-feedback', permission: 'student-feedback.view' },
    ],
  },
  {
    labelKey: 'adminNav.groups.settings',
    items: [
      { labelKey: 'adminNav.items.school', to: '/admin/school-settings', permission: 'tenant-settings.view' },
      { labelKey: 'adminNav.items.schoolDocuments', to: '/admin/school-documents', permission: 'tenant-settings.view' },
      { labelKey: 'adminNav.items.settings', to: '/admin/settings', permission: 'tenant-settings.view' },
      { labelKey: 'adminNav.items.users', to: '/admin/users', permission: 'users.view' },
      { labelKey: 'adminNav.items.roles', to: '/admin/roles', permission: 'roles.view' },
      { labelKey: 'adminNav.items.auditLogs', to: '/admin/audit-logs', permission: 'audit-logs.view' },
      { labelKey: 'adminNav.items.languages', to: '/admin/languages', permission: 'base-data.manage-languages' },
      { labelKey: 'adminNav.items.lookupCategories', to: '/admin/lookup-categories', permission: 'base-data.view' },
    ],
  },
  {
    // Forms and My Request are identity-gated at the API layer (submit/view
    // your own), same as LeaveRequest submission — but still permission-
    // gated here for sidebar visibility, same as every other item.
    labelKey: 'adminNav.groups.eApprovals',
    items: [
      { labelKey: 'adminNav.items.forms', to: '/admin/approvals/forms', permission: 'forms.view' },
      { labelKey: 'adminNav.items.myRequests', to: '/admin/approvals/my-requests', permission: 'my-requests.view' },
      { labelKey: 'adminNav.items.approvals', to: '/admin/approvals/queue', permission: ['approval-requests.view', 'leave-requests.view'] },
      { labelKey: 'adminNav.items.formCategories', to: '/admin/form-categories', permission: 'form-categories.manage' },
      { labelKey: 'adminNav.items.formTemplates', to: '/admin/form-templates', permission: 'form-templates.manage' },
    ],
  },
]

export interface AdminNavAccess {
  isSuperAdmin: boolean
  can: (permission: string) => boolean
  hasRole: (...slugs: string[]) => boolean
}

/** The same visibility rule AdminSidebar.vue applies per item — shared so the router guard's "can this account even reach this page" check can't drift from what the sidebar actually shows. */
export function isNavItemVisible(item: AdminNavItem, access: AdminNavAccess): boolean {
  if (item.superAdminOnly && !access.isSuperAdmin) return false
  if (item.studentOnly && !access.hasRole('student')) return false
  if (item.permission) {
    const required = Array.isArray(item.permission) ? item.permission : [item.permission]
    if (!required.some((permission) => access.can(permission))) return false
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
