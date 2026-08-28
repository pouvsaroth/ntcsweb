import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

const publicRoutes: RouteRecordRaw[] = [
  { path: '', name: 'home', component: () => import('@/pages/public/Home.vue') },
  { path: 'about', name: 'about', component: () => import('@/pages/public/About.vue') },
  { path: 'programs', name: 'programs', component: () => import('@/pages/public/Programs.vue') },
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
  ['roles', 'adminNav.items.roles'],
  ['settings', 'adminNav.items.settings'],
  ['academic-years', 'adminNav.items.academicYears'],
  ['programs', 'adminNav.items.programs'],
  ['subjects', 'adminNav.items.subjects'],
  ['classes', 'adminNav.items.classes'],
  ['students', 'adminNav.items.studentsList'],
  ['enrollments', 'adminNav.items.enrollments'],
  ['teachers', 'adminNav.items.teachersList'],
  ['attendance', 'adminNav.items.attendance'],
  ['exams', 'adminNav.items.exams'],
  ['grades', 'adminNav.items.grades'],
  ['news', 'adminNav.items.news'],
  ['events', 'adminNav.items.events'],
  ['announcements', 'adminNav.items.announcements'],
  ['gallery', 'adminNav.items.gallery'],
  ['documents', 'adminNav.items.documents'],
  ['contact-messages', 'adminNav.items.contactMessages'],
  ['notifications', 'adminNav.items.notifications'],
  ['audit-logs', 'adminNav.items.auditLogs'],
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
