import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

const publicRoutes: RouteRecordRaw[] = [
  // `/` is the "group of services" page while it is switched on (back office →
  // Website → Services page), else the school's home; which also lives at
  // /computer-school. Each card's own page is /services/{slug} (→ / while off).
  { path: '', name: 'home', component: () => import('@/pages/public/Landing.vue') },
  { path: 'computer-school', name: 'school-home', component: () => import('@/pages/public/Home.vue') },
  { path: 'services/:slug', name: 'service-page', component: () => import('@/pages/public/ServicePage.vue') },
  // The plans card's page (back office → Billing → Plans, docs/backoffice/stage-8-signup.md).
  { path: 'plans/:system?', name: 'plans', component: () => import('@/pages/public/Plans.vue') },
  // Self-service sign-up from a plan card, then its status page (8b).
  { path: 'signup', name: 'signup', component: () => import('@/pages/public/Signup.vue') },
  { path: 'signup/:token', name: 'signup-status', component: () => import('@/pages/public/SignupStatus.vue') },
  // Where the School Management System page first lived.
  { path: 'school-management-system', redirect: '/services/school-management-system' },
  { path: 'about', name: 'about', component: () => import('@/pages/public/About.vue') },
  { path: 'video-lessons', name: 'video-lessons', component: () => import('@/pages/public/VideoLessons.vue') },
  { path: 'programs', name: 'programs', component: () => import('@/pages/public/Programs.vue') },
  { path: 'register', name: 'register', component: () => import('@/pages/public/Register.vue') },
  { path: 'enrollment-inquiry', name: 'enrollment-inquiry', component: () => import('@/pages/public/EnrollmentInquiry.vue') },
  { path: 'teachers', name: 'teachers', component: () => import('@/pages/public/Teachers.vue') },
  { path: 'students', name: 'students', component: () => import('@/pages/public/Students.vue') },
  { path: 'news', name: 'news', component: () => import('@/pages/public/News.vue') },
  { path: 'news/:slug', name: 'news.detail', component: () => import('@/pages/public/NewsDetail.vue') },
  { path: 'events', name: 'events', component: () => import('@/pages/public/Events.vue') },
  { path: 'announcements', name: 'announcements', component: () => import('@/pages/public/Announcements.vue') },
  { path: 'gallery', name: 'gallery', component: () => import('@/pages/public/Gallery.vue') },
  { path: 'promotion', name: 'promotion', component: () => import('@/pages/public/Promotion.vue') },
  { path: 'downloads', name: 'downloads', component: () => import('@/pages/public/Downloads.vue') },
  { path: 'careers', name: 'careers', component: () => import('@/pages/public/Careers.vue') },
  { path: 'documents', name: 'documents', component: () => import('@/pages/public/Documents.vue') },
  { path: 'contact', name: 'contact', component: () => import('@/pages/public/Contact.vue') },
  { path: ':pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/public/NotFound.vue') },
]

/**
 * The public website's own router — no admin/auth routes here at all
 * anymore (see router/admin.ts for those, and main-admin.ts for that
 * bundle's entry point). This bundle is never served on the shared ERP
 * domain or a login path — docker/nginx/prod.conf's doc-root-per-domain
 * split enforces that at the infrastructure level, so no client-side
 * domain/auth guard is needed here either. A school's own domain hitting
 * `/admin`, `/login`, etc. gets an nginx-level redirect to the ERP domain
 * before this router ever sees the request.
 */
const router = createRouter({
  history: createWebHistory(),
  routes: [{ path: '/', component: () => import('@/layouts/PublicLayout.vue'), children: publicRoutes }],
  scrollBehavior(to, _from, savedPosition) {
    if (savedPosition) return savedPosition
    if (to.hash) return { el: to.hash, behavior: 'smooth' }
    return { top: 0 }
  },
})

export default router
