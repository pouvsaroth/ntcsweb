import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

import { setUnauthenticatedHandler } from './api'
import { useSession } from './session'

declare module 'vue-router' {
  interface RouteMeta {
    /** Signed-out page (sign-in). */
    guest?: boolean
    /** The platform.* permission the page needs — hidden from the menu and refused here without it. */
    permission?: string
  }
}

/** The menu, in order — ShellLayout draws it from this, filtered by permission. */
export const navItems = [
  { to: '/schools', labelKey: 'bo.nav.schools', permission: 'platform.school.read', icon: 'school' },
  { to: '/billing', labelKey: 'bo.nav.billing', permission: 'platform.billing.read', icon: 'card' },
  { to: '/data', labelKey: 'bo.nav.data', permission: 'platform.data.read', icon: 'database' },
  { to: '/support', labelKey: 'bo.nav.support', permission: 'platform.support.read', icon: 'eye' },
  { to: '/website', labelKey: 'bo.nav.website', permission: 'platform.website.read', icon: 'globe' },
  { to: '/staff', labelKey: 'bo.nav.staff', permission: 'platform.staff.read', icon: 'users' },
  { to: '/roles', labelKey: 'bo.nav.roles', permission: 'platform.role.read', icon: 'shield' },
  { to: '/audit', labelKey: 'bo.nav.audit', permission: 'platform.audit.read', icon: 'list' },
  { to: '/settings', labelKey: 'bo.nav.settings', permission: 'platform.security.manage', icon: 'cog' },
] as const

const routes: RouteRecordRaw[] = [
  {
    path: '/sign-in',
    component: () => import('./pages/SignIn.vue'),
    meta: { guest: true },
  },
  {
    path: '/',
    component: () => import('./ShellLayout.vue'),
    children: [
      { path: '', redirect: () => firstAllowedPage() },
      { path: 'schools', component: () => import('./pages/SchoolsPage.vue'), meta: { permission: 'platform.school.read' } },
      { path: 'schools/:id(\\d+)', component: () => import('./pages/SchoolDetailPage.vue'), meta: { permission: 'platform.school.read' } },
      {
        path: 'billing',
        component: () => import('./pages/billing/BillingLayout.vue'),
        meta: { permission: 'platform.billing.read' },
        children: [
          { path: '', redirect: '/billing/plans' },
          { path: 'systems', component: () => import('./pages/billing/SystemsTab.vue') },
          { path: 'plans', component: () => import('./pages/billing/PlansTab.vue') },
          { path: 'payments', component: () => import('./pages/billing/PaymentsTab.vue') },
          { path: 'signups', component: () => import('./pages/billing/SignupsTab.vue') },
          { path: 'settings', component: () => import('./pages/billing/SettingsTab.vue') },
        ],
      },
      {
        path: 'data',
        component: () => import('./pages/data/DataOpsLayout.vue'),
        meta: { permission: 'platform.data.read' },
        children: [
          { path: '', redirect: '/data/databases' },
          { path: 'databases', component: () => import('./pages/data/DatabasesTab.vue') },
          { path: 'backups', component: () => import('./pages/data/BackupsTab.vue') },
          { path: 'migrations', component: () => import('./pages/data/MigrationsTab.vue') },
          { path: 'health', component: () => import('./pages/data/HealthTab.vue') },
        ],
      },
      {
        path: 'support',
        component: () => import('./pages/support/SupportLayout.vue'),
        meta: { permission: 'platform.support.read' },
        children: [
          { path: '', redirect: '/support/views' },
          { path: 'views', component: () => import('./pages/support/ViewsTab.vue') },
          { path: 'settings', component: () => import('./pages/support/SettingsTab.vue') },
        ],
      },
      { path: 'staff', component: () => import('./pages/StaffPage.vue'), meta: { permission: 'platform.staff.read' } },
      { path: 'roles', component: () => import('./pages/RolesPage.vue'), meta: { permission: 'platform.role.read' } },
      { path: 'audit', component: () => import('./pages/AuditPage.vue'), meta: { permission: 'platform.audit.read' } },
      {
        path: 'website',
        component: () => import('./pages/website/WebsiteLayout.vue'),
        meta: { permission: 'platform.website.read' },
        children: [
          { path: '', redirect: '/website/services' },
          ...(['slides', 'gallery', 'promotions'] as const).map((kind) => ({
            path: kind,
            component: () => import('./pages/website/ImagesTab.vue'),
            props: { kind },
          })),
          { path: 'services', component: () => import('./pages/website/ServicesTab.vue') },
          { path: 'about', component: () => import('./pages/website/AboutTab.vue') },
          { path: 'downloads', component: () => import('./pages/website/DownloadsTab.vue') },
          ...(['news', 'events', 'announcements', 'documents'] as const).map((tab) => ({
            path: tab,
            component: () => import('./pages/website/ComingSoonTab.vue'),
            props: { tab },
          })),
        ],
      },
      {
        path: 'settings',
        component: () => import('./pages/settings/SettingsLayout.vue'),
        meta: { permission: 'platform.security.manage' },
        children: [
          { path: '', redirect: '/settings/security' },
          { path: 'security', component: () => import('./pages/settings/SecurityTab.vue') },
        ],
      },
      { path: 'account', component: () => import('./pages/AccountPage.vue') },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

function firstAllowedPage(): string {
  const session = useSession()
  return navItems.find((item) => session.can(item.permission))?.to ?? '/account'
}

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const session = useSession()
  const current = await session.current()

  if (to.meta.guest) return current ? '/' : true
  if (!current) return { path: '/sign-in', query: to.fullPath === '/' ? {} : { next: to.fullPath } }

  // The server refuses every other route until a temporary password is replaced.
  if (current.must_change_password && to.path !== '/account') return '/account'
  if (to.meta.permission && !session.can(to.meta.permission)) return firstAllowedPage()

  return true
})

setUnauthenticatedHandler(() => {
  useSession().expired()
  if (router.currentRoute.value.path !== '/sign-in') void router.push('/sign-in')
})

export default router
