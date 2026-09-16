/** The public site's navigation structure — app routing shape, not tenant content, so it's fine to declare statically here. `labelKey` is resolved with useI18n()'s t() by consumers. */
export interface NavItem {
  labelKey: string
  to: string
}

export const publicNav: NavItem[] = [
  { labelKey: 'nav.home', to: '/' },
  { labelKey: 'nav.about', to: '/about' },
  { labelKey: 'nav.videoLesson', to: '/video-lessons' },
  { labelKey: 'nav.programs', to: '/programs' },
  { labelKey: 'nav.schedule', to: '/schedule' },
  { labelKey: 'nav.gallery', to: '/gallery' },
  { labelKey: 'nav.promotion', to: '/promotion' },
  { labelKey: 'nav.contact', to: '/contact' },
]

/**
 * The "Document and Form" dropdown's Form group — every route here sits
 * under `/admin`, which already carries `meta: { requiresAuth: true }` (see
 * router/index.ts's beforeEach), so clicking one while signed out redirects
 * to Login with `?redirect=` and lands back here after signing in, with no
 * extra wiring needed. Staff's own "Request Leave" and "Resignation Form"
 * live in the admin panel's Staff nav group instead (see adminNav.ts) — not
 * here, since this menu is the public-site entry point mainly meant for
 * students/visitors.
 */
export const documentsAndFormLinks: NavItem[] = [
  { labelKey: 'nav.documentsAndForm.requestComment', to: '/admin/my-feedback' },
  { labelKey: 'nav.documentsAndForm.studentRequestLeave', to: '/admin/approvals/my-requests' },
  { labelKey: 'nav.documentsAndForm.examApplicationForm', to: '/admin/my-exam-applications' },
  // These two land on the Forms catalog with the matching template's request
  // modal already open — see Forms.vue's `?code=` handling. The templates
  // themselves are seeded for every school (codes CHANGE-CLASS/EXTRA-CLASS,
  // see the 2026_09_16_030000 tenant migration) so this works out of the box.
  { labelKey: 'nav.documentsAndForm.changeClass', to: '/admin/approvals/forms?code=CHANGE-CLASS' },
  { labelKey: 'nav.documentsAndForm.extraClasses', to: '/admin/approvals/forms?code=EXTRA-CLASS' },
]
