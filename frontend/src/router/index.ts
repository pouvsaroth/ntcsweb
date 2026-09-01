import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

const publicRoutes: RouteRecordRaw[] = [
  { path: '', name: 'home', component: () => import('@/pages/public/Home.vue') },
  { path: 'about', name: 'about', component: () => import('@/pages/public/About.vue') },
  { path: 'programs', name: 'programs', component: () => import('@/pages/public/Programs.vue') },
  { path: 'schedule', name: 'schedule', component: () => import('@/pages/public/Schedule.vue') },
  { path: 'register', name: 'register', component: () => import('@/pages/public/Register.vue') },
  { path: 'teachers', name: 'teachers', component: () => import('@/pages/public/Teachers.vue') },
  { path: 'students', name: 'students', component: () => import('@/pages/public/Students.vue') },
  { path: 'news', name: 'news', component: () => import('@/pages/public/News.vue') },
  { path: 'news/:slug', name: 'news.detail', component: () => import('@/pages/public/NewsDetail.vue') },
  { path: 'events', name: 'events', component: () => import('@/pages/public/Events.vue') },
  { path: 'announcements', name: 'announcements', component: () => import('@/pages/public/Announcements.vue') },
  { path: 'gallery', name: 'gallery', component: () => import('@/pages/public/Gallery.vue') },
  { path: 'documents', name: 'documents', component: () => import('@/pages/public/Documents.vue') },
  { path: 'contact', name: 'contact', component: () => import('@/pages/public/Contact.vue') },
  { path: ':pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/public/NotFound.vue') },
]

const authRoutes: RouteRecordRaw[] = [
  { path: 'login', name: 'login', component: () => import('@/pages/auth/Login.vue'), meta: { guestOnly: true } },
  {
    path: 'forgot-password',
    name: 'forgot-password',
    component: () => import('@/pages/auth/ForgotPassword.vue'),
    meta: { guestOnly: true },
  },
  {
    path: 'reset-password',
    name: 'reset-password',
    component: () => import('@/pages/auth/ResetPassword.vue'),
    meta: { guestOnly: true },
  },
]

const comingSoon = () => import('@/pages/admin/ComingSoon.vue')

/** path -> adminNav.items translation key, so ComingSoon.vue's title always matches the sidebar label it was clicked from. */
const comingSoonPages: [string, string][] = [
  ['tenants', 'adminNav.items.tenants'],
  ['academic-years', 'adminNav.items.academicYears'],
  ['subjects', 'adminNav.items.subjects'],
  ['teachers', 'adminNav.items.teachersList'],
  ['exams', 'adminNav.items.exams'],
  ['grades', 'adminNav.items.grades'],
  ['news', 'adminNav.items.news'],
  ['events', 'adminNav.items.events'],
  ['announcements', 'adminNav.items.announcements'],
  ['documents', 'adminNav.items.documents'],
  ['contact-messages', 'adminNav.items.contactMessages'],
  ['notifications', 'adminNav.items.notifications'],
  // Not in adminNav.ts's sidebar — reachable only via StudentBottomNav's
  // mobile tab bar, shown to a signed-in student.
  ['my-scores', 'studentNav.score'],
  ['my-videos', 'studentNav.video'],
]

const adminRoutes: RouteRecordRaw[] = [
  {
    path: '',
    name: 'admin.dashboard',
    component: () => import('@/pages/admin/Dashboard.vue'),
    meta: { titleKey: 'adminNav.items.dashboard' },
  },
  {
    path: 'users',
    name: 'admin.users',
    component: () => import('@/pages/admin/Users.vue'),
    meta: { titleKey: 'adminNav.items.users' },
  },
  {
    path: 'home-slides',
    name: 'admin.home-slides',
    component: () => import('@/pages/admin/HomeSlides.vue'),
    meta: { titleKey: 'adminNav.items.homeSlides' },
  },
  {
    path: 'gallery',
    name: 'admin.gallery',
    component: () => import('@/pages/admin/Gallery.vue'),
    meta: { titleKey: 'adminNav.items.gallery' },
  },
  {
    path: 'student-imports',
    name: 'admin.student-imports',
    component: () => import('@/pages/admin/StudentImports.vue'),
    meta: { titleKey: 'adminNav.items.studentImports' },
  },
  {
    path: 'programs',
    name: 'admin.programs',
    component: () => import('@/pages/admin/Programs.vue'),
    meta: { titleKey: 'adminNav.items.programs' },
  },
  {
    path: 'about-page',
    name: 'admin.about-page',
    component: () => import('@/pages/admin/AboutPage.vue'),
    meta: { titleKey: 'adminNav.items.aboutPage' },
  },
  {
    path: 'students',
    name: 'admin.students',
    component: () => import('@/pages/admin/Students.vue'),
    meta: { titleKey: 'adminNav.items.studentsList' },
  },
  {
    path: 'students/new',
    name: 'admin.students.new',
    component: () => import('@/pages/admin/StudentForm.vue'),
    meta: { titleKey: 'adminNav.items.studentsList' },
  },
  {
    path: 'students/:id/edit',
    name: 'admin.students.edit',
    component: () => import('@/pages/admin/StudentForm.vue'),
    meta: { titleKey: 'adminNav.items.studentsList' },
  },
  {
    path: 'books',
    name: 'admin.books',
    component: () => import('@/pages/admin/Books.vue'),
    meta: { titleKey: 'adminNav.items.books' },
  },
  {
    path: 'classes',
    name: 'admin.classes',
    component: () => import('@/pages/admin/Classes.vue'),
    meta: { titleKey: 'adminNav.items.classes' },
  },
  {
    path: 'classes/new',
    name: 'admin.classes.new',
    component: () => import('@/pages/admin/ClassForm.vue'),
    meta: { titleKey: 'adminNav.items.classes' },
  },
  {
    path: 'classes/:id/edit',
    name: 'admin.classes.edit',
    component: () => import('@/pages/admin/ClassForm.vue'),
    meta: { titleKey: 'adminNav.items.classes' },
  },
  {
    path: 'enrollments',
    name: 'admin.enrollments',
    component: () => import('@/pages/admin/Enrollments.vue'),
    meta: { titleKey: 'adminNav.items.enrollments' },
  },
  {
    path: 'enrollments/new',
    name: 'admin.enrollments.new',
    component: () => import('@/pages/admin/EnrollmentForm.vue'),
    meta: { titleKey: 'adminNav.items.enrollments' },
  },
  {
    path: 'attendance',
    name: 'admin.attendance',
    component: () => import('@/pages/admin/Attendance.vue'),
    meta: { titleKey: 'adminNav.items.attendance' },
  },
  {
    path: 'my-attendance',
    name: 'admin.my-attendance',
    component: () => import('@/pages/admin/MyAttendance.vue'),
    meta: { titleKey: 'studentNav.attendant' },
  },
  {
    path: 'staff',
    name: 'admin.staff',
    component: () => import('@/pages/admin/Staff.vue'),
    meta: { titleKey: 'adminNav.items.staffList' },
  },
  {
    path: 'staff/new',
    name: 'admin.staff.new',
    component: () => import('@/pages/admin/StaffForm.vue'),
    meta: { titleKey: 'adminNav.items.staffList' },
  },
  {
    path: 'staff/:id/edit',
    name: 'admin.staff.edit',
    component: () => import('@/pages/admin/StaffForm.vue'),
    meta: { titleKey: 'adminNav.items.staffList' },
  },
  {
    path: 'positions',
    name: 'admin.positions',
    component: () => import('@/pages/admin/Positions.vue'),
    meta: { titleKey: 'adminNav.items.positions' },
  },
  {
    path: 'roles',
    name: 'admin.roles',
    component: () => import('@/pages/admin/Roles.vue'),
    meta: { titleKey: 'adminNav.items.roles' },
  },
  {
    path: 'audit-logs',
    name: 'admin.audit-logs',
    component: () => import('@/pages/admin/AuditLogs.vue'),
    meta: { titleKey: 'adminNav.items.auditLogs' },
  },
  {
    path: 'school-settings',
    name: 'admin.school-settings',
    component: () => import('@/pages/admin/SchoolSettings.vue'),
    meta: { titleKey: 'adminNav.items.school' },
  },
  {
    path: 'billing',
    name: 'admin.billing',
    component: () => import('@/pages/admin/BillingDashboard.vue'),
    meta: { titleKey: 'adminNav.items.billingDashboard' },
  },
  {
    path: 'products',
    name: 'admin.products',
    component: () => import('@/pages/admin/Products.vue'),
    meta: { titleKey: 'adminNav.items.products' },
  },
  {
    path: 'invoices',
    name: 'admin.invoices',
    component: () => import('@/pages/admin/Invoices.vue'),
    meta: { titleKey: 'adminNav.items.invoices' },
  },
  {
    path: 'invoices/new',
    name: 'admin.invoices.new',
    component: () => import('@/pages/admin/InvoiceForm.vue'),
    meta: { titleKey: 'adminNav.items.invoices' },
  },
  {
    path: 'invoices/:id',
    name: 'admin.invoices.show',
    component: () => import('@/pages/admin/InvoiceDetail.vue'),
    meta: { titleKey: 'adminNav.items.invoices' },
  },
  {
    path: 'payments',
    name: 'admin.payments',
    component: () => import('@/pages/admin/Payments.vue'),
    meta: { titleKey: 'adminNav.items.payments' },
  },
  {
    path: 'accounting',
    name: 'admin.accounting',
    component: () => import('@/pages/admin/AccountingDashboard.vue'),
    meta: { titleKey: 'adminNav.items.accountingDashboard' },
  },
  {
    path: 'accounts',
    name: 'admin.accounts',
    component: () => import('@/pages/admin/Accounts.vue'),
    meta: { titleKey: 'adminNav.items.accounts' },
  },
  {
    path: 'income',
    name: 'admin.income',
    component: () => import('@/pages/admin/Income.vue'),
    meta: { titleKey: 'adminNav.items.income' },
  },
  {
    path: 'expenses',
    name: 'admin.expenses',
    component: () => import('@/pages/admin/Expenses.vue'),
    meta: { titleKey: 'adminNav.items.expenses' },
  },
  {
    path: 'expenses/new',
    name: 'admin.expenses.new',
    component: () => import('@/pages/admin/ExpenseForm.vue'),
    meta: { titleKey: 'adminNav.items.expenses' },
  },
  {
    path: 'expenses/:id',
    name: 'admin.expenses.show',
    component: () => import('@/pages/admin/ExpenseDetail.vue'),
    meta: { titleKey: 'adminNav.items.expenses' },
  },
  {
    path: 'transactions',
    name: 'admin.transactions',
    component: () => import('@/pages/admin/Transactions.vue'),
    meta: { titleKey: 'adminNav.items.transactions' },
  },
  {
    path: 'accounting-reports',
    name: 'admin.accounting-reports',
    component: () => import('@/pages/admin/AccountingReports.vue'),
    meta: { titleKey: 'adminNav.items.accountingReports' },
  },
  {
    path: 'settings',
    name: 'admin.settings',
    component: () => import('@/pages/admin/GeneralSettings.vue'),
    meta: { titleKey: 'adminNav.items.settings' },
  },
  ...comingSoonPages.map(
    ([path, titleKey]): RouteRecordRaw => ({
      path,
      name: `admin.${path}`,
      component: comingSoon,
      meta: { titleKey },
    }),
  ),
]

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', component: () => import('@/layouts/PublicLayout.vue'), children: publicRoutes },
    { path: '/', component: () => import('@/layouts/AuthLayout.vue'), children: authRoutes },
    {
      path: '/admin',
      component: () => import('@/layouts/AdminLayout.vue'),
      meta: { requiresAuth: true },
      children: adminRoutes,
    },
  ],
  scrollBehavior(to, _from, savedPosition) {
    if (savedPosition) return savedPosition
    if (to.hash) return { el: to.hash, behavior: 'smooth' }
    return { top: 0 }
  },
})

/**
 * Auth is resolved once, lazily, on the first navigation that needs it — not
 * eagerly on every app boot — so public pages never wait on an /auth/me
 * round trip they don't need.
 */
router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!auth.initialized && (to.meta.requiresAuth || to.meta.guestOnly)) {
    await auth.initialize()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'admin.dashboard' }
  }

  return true
})

export default router
