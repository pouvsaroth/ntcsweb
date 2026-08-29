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
    labelKey: 'adminNav.groups.school',
    items: [{ labelKey: 'adminNav.items.settings', to: '/admin/settings', permission: 'tenant-settings.view' }],
  },
  {
    labelKey: 'adminNav.groups.usersAccess',
    items: [
      { labelKey: 'adminNav.items.users', to: '/admin/users', permission: 'users.view' },
      { labelKey: 'adminNav.items.roles', to: '/admin/roles', permission: 'roles.view' },
    ],
  },
  {
    labelKey: 'adminNav.groups.academic',
    items: [
      { labelKey: 'adminNav.items.academicYears', to: '/admin/academic-years' },
      { labelKey: 'adminNav.items.programs', to: '/admin/programs', permission: 'programs.view' },
      { labelKey: 'adminNav.items.subjects', to: '/admin/subjects' },
      { labelKey: 'adminNav.items.books', to: '/admin/books' },
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
    labelKey: 'adminNav.groups.teachers',
    items: [{ labelKey: 'adminNav.items.teachersList', to: '/admin/teachers' }],
  },
  {
    labelKey: 'adminNav.groups.academicRecords',
    items: [
      { labelKey: 'adminNav.items.attendance', to: '/admin/attendance' },
      { labelKey: 'adminNav.items.exams', to: '/admin/exams' },
      { labelKey: 'adminNav.items.grades', to: '/admin/grades' },
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
      { labelKey: 'adminNav.items.gallery', to: '/admin/gallery' },
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
    labelKey: 'adminNav.groups.system',
    items: [{ labelKey: 'adminNav.items.auditLogs', to: '/admin/audit-logs', permission: 'audit-logs.view' }],
  },
]
