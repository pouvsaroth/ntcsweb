/** The public site's navigation structure — app routing shape, not tenant content, so it's fine to declare statically here. `labelKey` is resolved with useI18n()'s t() by consumers. */
export interface NavItem {
  labelKey: string
  to: string
}

/** Rendered before the "Program" dropdown in the header — see PublicHeader.vue. */
export const publicNavBeforeProgram: NavItem[] = [
  { labelKey: 'nav.home', to: '/' },
  { labelKey: 'nav.about', to: '/about' },
]

/**
 * The "Program" dropdown's items — folds what used to be four separate
 * top-level links (Programs, Day and Time Study, Video Lesson, Photos) into
 * one, so the header doesn't run out of room as more menus (Promotion,
 * Document and Form) get added alongside it.
 */
export const programNav: NavItem[] = [
  { labelKey: 'nav.programs', to: '/programs' },
  { labelKey: 'nav.schedule', to: '/schedule' },
  { labelKey: 'nav.videoLesson', to: '/video-lessons' },
  { labelKey: 'nav.gallery', to: '/gallery' },
]

/** Rendered after the "Program" dropdown, before "Document and Form" — see PublicHeader.vue. */
export const publicNavAfterProgram: NavItem[] = [
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
]
