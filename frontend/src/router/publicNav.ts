/** The public site's navigation structure — app routing shape, not tenant content, so it's fine to declare statically here. `labelKey` is resolved with useI18n()'s t() by consumers. */
export interface NavItem {
  labelKey: string
  to: string
}

/** Rendered before the "Program" link in the header — see PublicHeader.vue. */
export const publicNavBeforeProgram: NavItem[] = [
  { labelKey: 'nav.home', to: '/' },
  { labelKey: 'nav.about', to: '/about' },
]

/**
 * The "Program" link — a single link straight to the Programs page, not a
 * dropdown. Used to fold Programs/Day and Time Study/Video Lesson/Photos
 * together as separate destinations in one menu; now the Programs page
 * itself shows both the course listing and the Day and Time Study schedule
 * (see Programs.vue), so there's nothing left to open a submenu for.
 */
export const programNavItem: NavItem = { labelKey: 'nav.program', to: '/programs' }

/**
 * Rendered after the "Program" link, before "Document and Form" — see
 * PublicHeader.vue. No standalone Video Lesson link: every course card
 * already surfaces its own video (see CourseCard.vue), so a separate global
 * "Video Lesson" menu entry would just be a redundant path to the same
 * content.
 */
export const publicNavAfterProgram: NavItem[] = [
  { labelKey: 'nav.gallery', to: '/gallery' },
  { labelKey: 'nav.promotion', to: '/promotion' },
  { labelKey: 'nav.contact', to: '/contact' },
]

/**
 * The "Document and Form" group's Form links — every route here sits under
 * `/admin`, which already carries `meta: { requiresAuth: true }` (see
 * router/index.ts's beforeEach). Rendered inside PublicUserMenu.vue's
 * student section rather than as a standalone header menu, since these are
 * only ever relevant to a signed-in student. Staff's own "Request Leave" and
 * "Resignation Form" live in the admin panel's Staff nav group instead (see
 * adminNav.ts).
 */
export const documentsAndFormLinks: NavItem[] = [
  { labelKey: 'nav.documentsAndForm.requestComment', to: '/admin/my-feedback' },
  { labelKey: 'nav.documentsAndForm.studentRequestLeave', to: '/admin/approvals/my-requests' },
  { labelKey: 'nav.documentsAndForm.examApplicationForm', to: '/admin/my-exam-applications' },
]

/**
 * The "Document and Form" group's Document links — external files a school
 * admin uploaded under School Documents (see siteStore's `info.documents`),
 * so `urlKey` names the field on that object rather than a route. Rendered
 * alongside `documentsAndFormLinks` in PublicUserMenu.vue's student section.
 */
export const documentLinks: { labelKey: string; urlKey: 'school_regulation_url' | 'student_attendance_policy_url' }[] = [
  { labelKey: 'nav.documentsAndForm.schoolRegulation', urlKey: 'school_regulation_url' },
  { labelKey: 'nav.documentsAndForm.attendancePolicy', urlKey: 'student_attendance_policy_url' },
]
