/** The public site's navigation structure — app routing shape, not tenant content, so it's fine to declare statically here. `labelKey` is resolved with useI18n()'s t() by consumers. */
export interface NavItem {
  labelKey: string
  to: string
}

export const publicNav: NavItem[] = [
  { labelKey: 'nav.home', to: '/' },
  { labelKey: 'nav.about', to: '/about' },
  { labelKey: 'nav.programs', to: '/programs' },
  { labelKey: 'nav.schedule', to: '/schedule' },
  { labelKey: 'nav.gallery', to: '/gallery' },
  { labelKey: 'nav.contact', to: '/contact' },
]
