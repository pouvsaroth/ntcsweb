export interface AdminNavItem {
  labelKey: string
  to: string
  /** Only shown to a platform Super Admin (tenant_id IS NULL). */
  superAdminOnly?: boolean
  /** A permission slug required to see this item; omitted means "any authenticated admin". */
  permission?: string
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
    items: [{ labelKey: 'adminNav.items.dashboard', to: '/admin' }],
  },
  {
    labelKey: 'adminNav.groups.platform',
    items: [{ labelKey: 'adminNav.items.tenants', to: '/admin/tenants', superAdminOnly: true }],
  },
  {
    labelKey: 'adminNav.groups.academic',
    items: [
      { labelKey: 'adminNav.items.academicYears', to: '/admin/academic-years', permission: 'academic-years.view' },
      { labelKey: 'adminNav.items.studyModes', to: '/admin/study-modes', permission: 'study-modes.view' },
      { labelKey: 'adminNav.items.academicPrograms', to: '/admin/academic-programs', permission: 'academic-programs.view' },
      { labelKey: 'adminNav.items.coursePackages', to: '/admin/course-packages', permission: 'course-packages.view' },
      { labelKey: 'adminNav.items.bookCategories', to: '/admin/book-categories', permission: 'book-categories.view' },
      { labelKey: 'adminNav.items.books', to: '/admin/books' },
      { labelKey: 'adminNav.items.buildings', to: '/admin/buildings', permission: 'buildings.view' },
      { labelKey: 'adminNav.items.classrooms', to: '/admin/classrooms', permission: 'classrooms.view' },
      { labelKey: 'adminNav.items.classes', to: '/admin/classes' },
    ],
  },
  {
    labelKey: 'adminNav.groups.students',
    items: [
      { labelKey: 'adminNav.items.studentsList', to: '/admin/students' },
      { labelKey: 'adminNav.items.studentImports', to: '/admin/student-imports', permission: 'students.create' },
      { labelKey: 'adminNav.items.enrollments', to: '/admin/enrollments' },
    ],
  },
  {
    labelKey: 'adminNav.groups.staff',
    items: [{ labelKey: 'adminNav.items.staffList', to: '/admin/staff', permission: 'staff.view' }],
  },
  {
    labelKey: 'adminNav.groups.academicRecords',
    items: [
      { labelKey: 'adminNav.items.attendance', to: '/admin/attendance', permission: 'attendance.view' },
      { labelKey: 'adminNav.items.exams', to: '/admin/exams' },
      { labelKey: 'adminNav.items.grades', to: '/admin/grades' },
    ],
  },
  {
    labelKey: 'adminNav.groups.billing',
    items: [
      { labelKey: 'adminNav.items.billingDashboard', to: '/admin/billing', permission: 'billing-reports.view' },
      { labelKey: 'adminNav.items.products', to: '/admin/products', permission: 'products.view' },
      { labelKey: 'adminNav.items.invoices', to: '/admin/invoices', permission: 'invoices.view' },
      { labelKey: 'adminNav.items.payments', to: '/admin/payments', permission: 'payments.view' },
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
      { labelKey: 'adminNav.items.myAssets', to: '/admin/my-assets' },
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
      { labelKey: 'adminNav.items.news', to: '/admin/news' },
      { labelKey: 'adminNav.items.events', to: '/admin/events' },
      { labelKey: 'adminNav.items.announcements', to: '/admin/announcements' },
      { labelKey: 'adminNav.items.gallery', to: '/admin/gallery', permission: 'gallery.view' },
      { labelKey: 'adminNav.items.documents', to: '/admin/documents' },
    ],
  },
  {
    labelKey: 'adminNav.groups.communication',
    items: [
      { labelKey: 'adminNav.items.contactMessages', to: '/admin/contact-messages' },
      { labelKey: 'adminNav.items.notifications', to: '/admin/notifications' },
    ],
  },
  {
    labelKey: 'adminNav.groups.settings',
    items: [
      { labelKey: 'adminNav.items.school', to: '/admin/school-settings', permission: 'tenant-settings.view' },
      { labelKey: 'adminNav.items.settings', to: '/admin/settings', permission: 'tenant-settings.view' },
      { labelKey: 'adminNav.items.users', to: '/admin/users', permission: 'users.view' },
      { labelKey: 'adminNav.items.roles', to: '/admin/roles', permission: 'roles.view' },
      { labelKey: 'adminNav.items.positions', to: '/admin/positions', permission: 'positions.view' },
      { labelKey: 'adminNav.items.auditLogs', to: '/admin/audit-logs', permission: 'audit-logs.view' },
      { labelKey: 'adminNav.items.languages', to: '/admin/languages', permission: 'base-data.manage-languages' },
      { labelKey: 'adminNav.items.lookupCategories', to: '/admin/lookup-categories', permission: 'base-data.view' },
    ],
  },
]
