/**
 * An organization type's own sidebar (Back office > Organization types >
 * Menu layout, backend App\Platform\Support\MenuLayout): groups reordered,
 * new groups, menu items moved / renamed / hidden, page tabs reordered and
 * tabs taken out of their page as menu items.
 *
 * Anything the layout does not mention stays where adminNav.ts puts it, so a
 * menu added later still shows. Hiding only changes the menu: plans and
 * roles still decide what a user may open (isNavItemVisible, router guard).
 *
 * No store imports here: the back office editor uses the same catalog.
 */
import { adminNav, type AdminNavGroup, type AdminNavItem } from '@/router/adminNav'
import { attendanceRecordTabs, attendanceTabs } from '@/router/attendanceTabs'
import { borrowingsTabs } from '@/router/borrowingsTabs'
import { examinationTabs } from '@/router/examinationTabs'
import { expenseTabs } from '@/router/expenseTabs'
import { frontOfficeTabs } from '@/router/frontOfficeTabs'
import { leaveTabs } from '@/router/leaveTabs'
import { lessonTabs } from '@/router/lessonTabs'
import { marketingTabs } from '@/router/marketingTabs'
import { organizationTabs } from '@/router/organizationTabs'
import { payrollTabs } from '@/router/payrollTabs'
import { performanceTabs } from '@/router/performanceTabs'
import { professionalDevelopmentTabs } from '@/router/professionalDevelopmentTabs'
import { recruitmentTabs } from '@/router/recruitmentTabs'
import { schoolSetupTabs } from '@/router/schoolSetupTabs'
import { teacherTabs } from '@/router/teacherTabs'
import { timetableTabs } from '@/router/timetableTabs'

export const MENU_LAYOUT_LANGS = ['en', 'km', 'zh', 'ja', 'ko'] as const
export type MenuLayoutNames = Partial<Record<(typeof MENU_LAYOUT_LANGS)[number], string>>

export interface MenuLayoutItem {
  to: string
  /** The group it comes from (a path can sit in two groups), or 'tab' for a tab taken out. */
  from?: string
  names?: MenuLayoutNames
  hidden?: boolean
}

export interface MenuLayoutGroup {
  /** 'adminNav.groups.staff' or a new group 'custom:politic'. */
  key: string
  names?: MenuLayoutNames
  icon?: string
  hidden?: boolean
  items: MenuLayoutItem[]
}

export interface MenuLayout {
  groups: MenuLayoutGroup[]
  /** Tab order per page, keyed by the page's first standard tab. */
  tabs?: Record<string, string[]>
}

/** A nav group / item once a layout renamed it: `label` (already in the user's language) wins over labelKey. */
export type LaidOutItem = AdminNavItem & { label?: string; takenOutTab?: boolean }
export type LaidOutGroup = Omit<AdminNavGroup, 'items'> & { label?: string; items: LaidOutItem[] }

export interface PageTab {
  to: string
  labelKey: string
  /** Any one of these opens the tab; none = anyone who can open the page. */
  permission?: string[]
}

export interface TabBar {
  /** The page's first standard tab — the key of its order in a layout. */
  key: string
  /** The menu item the page is opened from, for the editor's heading. */
  labelKey: string
  tabs: PageTab[]
}

type RawTab = { to: string; labelKey: string; permission?: string | string[]; permissions?: string[] }
const bar = (labelKey: string, tabs: RawTab[]): TabBar => ({
  key: tabs[0]!.to,
  labelKey,
  tabs: tabs.map((tab) => {
    const p = tab.permissions ?? tab.permission
    return { to: tab.to, labelKey: tab.labelKey, permission: p === undefined ? undefined : Array.isArray(p) ? p : [p] }
  }),
})

/**
 * Every page tab bar in the console. The pages that draw their own bar
 * (components/admin/*Tabs.vue, HelpLayout) repeat their tabs here.
 */
export const TAB_CATALOG: TabBar[] = [
  bar('adminNav.items.programs', [
    { to: '/admin/academic-programs', labelKey: 'adminNav.items.academicPrograms', permission: 'academic-programs.view' },
    { to: '/admin/academic-years', labelKey: 'adminNav.items.academicYears', permission: 'academic-years.view' },
    { to: '/admin/course-packages', labelKey: 'adminNav.items.coursePackages', permission: 'course-packages.view' },
    { to: '/admin/books', labelKey: 'adminNav.items.books', permission: 'books.view' },
    { to: '/admin/book-categories', labelKey: 'adminNav.items.bookCategories', permission: 'book-categories.view' },
  ]),
  bar('adminNav.items.schoolSetup', schoolSetupTabs),
  bar('adminNav.items.studyBuilding', [
    { to: '/admin/buildings', labelKey: 'adminNav.items.buildings', permission: 'buildings.view' },
    { to: '/admin/classrooms', labelKey: 'adminNav.items.classrooms', permission: 'classrooms.view' },
  ]),
  bar('adminNav.items.timetable', timetableTabs),
  bar('adminNav.items.lessonPlans', lessonTabs),
  bar('adminNav.items.attendance', [
    { to: '/admin/attendance', labelKey: 'admin.attendance.title', permission: 'attendance.view' },
    { to: '/admin/attendance/summary', labelKey: 'admin.attendance.summaryTab', permission: 'attendance.view' },
    { to: '/admin/attendance/sheet', labelKey: 'admin.attendance.sheetTab', permission: 'attendance.view' },
  ]),
  bar('adminNav.items.examination', examinationTabs),
  bar('adminNav.items.teachers', teacherTabs),
  bar('adminNav.items.organizationManagement', organizationTabs),
  bar('adminNav.items.recruitment', recruitmentTabs),
  bar('adminNav.items.timeAttendance', attendanceTabs),
  bar('adminNav.items.timeAttendance', attendanceRecordTabs),
  bar('adminNav.items.leaveManagement', leaveTabs),
  bar('adminNav.items.payroll', payrollTabs),
  bar('adminNav.items.performance', performanceTabs),
  bar('adminNav.items.professionalDevelopment', professionalDevelopmentTabs),
  bar('adminNav.items.invoices', [
    { to: '/admin/invoices', labelKey: 'admin.invoices.title', permission: 'invoices.view' },
    { to: '/admin/invoices/monthly', labelKey: 'admin.invoices.monthlyTab', permission: 'invoices.view' },
    { to: '/admin/invoices/discount-codes', labelKey: 'admin.discountCodes.tab', permission: 'discount-codes.view' },
    { to: '/admin/invoices/discount-students', labelKey: 'admin.discountStudents.tab', permission: 'discount-codes.view' },
  ]),
  bar('adminNav.items.expenses', expenseTabs),
  bar('adminNav.groups.frontOffice', frontOfficeTabs),
  bar('adminNav.groups.marketing', marketingTabs),
  bar('adminNav.items.borrowings', borrowingsTabs),
]

export const TAB_BY_PATH = new Map(TAB_CATALOG.flatMap((b) => b.tabs.map((tab) => [tab.to, tab] as const)))

/**
 * The groups a layout may change. The student and parent menus (flat) stay
 * as they are; they are the whole sidebar of those logins.
 */
export const LAYOUT_GROUPS = adminNav.filter((group) => !group.flat)

export function nameIn(names: MenuLayoutNames | undefined, locale: string): string | undefined {
  if (!names) return undefined
  return names[locale as keyof MenuLayoutNames] || names.en || undefined
}

/** Tabs taken out of their page and put in a group as menu items. */
export function takenOutTabs(layout: MenuLayout | null | undefined): Set<string> {
  const out = new Set<string>()
  for (const group of layout?.groups ?? []) {
    for (const item of group.items) if (item.from === 'tab' && TAB_BY_PATH.has(item.to)) out.add(item.to)
  }
  return out
}

/** A page's tabs in the layout's order; tabs the order leaves out keep their place after it. */
export function orderTabs<T extends { to: string }>(tabs: readonly T[], layout: MenuLayout | null | undefined): T[] {
  const order = tabs.length ? layout?.tabs?.[TAB_CATALOG.find((b) => b.tabs.some((tab) => tab.to === tabs[0]!.to))?.key ?? ''] : undefined
  if (!order?.length) return [...tabs]
  const rank = (to: string) => {
    const i = order.indexOf(to)
    return i === -1 ? order.length + tabs.findIndex((tab) => tab.to === to) : i
  }
  return [...tabs].sort((a, b) => rank(a.to) - rank(b.to))
}

/** The sidebar as this organization type lays it out; the standard menu when there is no layout. */
export function applyMenuLayout(layout: MenuLayout | null | undefined, locale: string): LaidOutGroup[] {
  if (!layout?.groups?.length) return adminNav
  const byKey = new Map(LAYOUT_GROUPS.map((group) => [group.labelKey, group]))
  const used = new Set<string>()
  const id = (groupKey: string, to: string | undefined) => `${groupKey}|${to ?? ''}`
  const hiddenGroups = new Set<string>()
  const result: LaidOutGroup[] = []

  for (const lg of layout.groups) {
    const base = byKey.get(lg.key)
    if (!base && !lg.key.startsWith('custom:')) continue // a group the console no longer has
    if (lg.hidden) hiddenGroups.add(lg.key)
    const items: LaidOutItem[] = []
    for (const li of lg.items) {
      let item: LaidOutItem | undefined
      if (li.from === 'tab') {
        const tab = TAB_BY_PATH.get(li.to)
        if (tab) item = { labelKey: tab.labelKey, icon: 'template', to: tab.to, permission: tab.permission, takenOutTab: true }
      } else {
        const fromGroup = li.from ? byKey.get(li.from) : LAYOUT_GROUPS.find((g) => g.items.some((i) => i.to === li.to))
        const found = fromGroup?.items.find((i) => i.to === li.to)
        if (fromGroup && found && !used.has(id(fromGroup.labelKey, found.to))) {
          used.add(id(fromGroup.labelKey, found.to))
          item = { ...found }
        }
      }
      if (!item || li.hidden) continue
      const label = nameIn(li.names, locale)
      items.push(label ? { ...item, label } : item)
    }
    const label = nameIn(lg.names, locale)
    result.push({
      ...(base ?? { labelKey: lg.key, icon: lg.icon || 'collection' }),
      ...(label ? { label } : {}),
      ...(lg.icon ? { icon: lg.icon } : {}),
      items,
    } as LaidOutGroup)
  }

  // Groups the layout leaves out (student / parent menus, groups added
  // later) go back right after the group before them in the standard menu.
  adminNav.forEach((group, index) => {
    if (result.some((g) => g.labelKey === group.labelKey && !g.labelKey.startsWith('custom:'))) return
    const before = adminNav.slice(0, index).reverse().find((g) => result.some((r) => r.labelKey === g.labelKey))
    const at = before ? result.findIndex((r) => r.labelKey === before.labelKey) + 1 : 0
    result.splice(at, 0, { ...group, items: [] })
  })

  // Items the layout leaves out stay in their own group, after the item before them.
  for (const group of adminNav) {
    const target = result.find((g) => g.labelKey === group.labelKey)
    if (!target) continue
    group.items.forEach((item, index) => {
      if (used.has(id(group.labelKey, item.to))) return
      const before = group.items.slice(0, index).reverse().find((i) => target.items.includes(i) || target.items.some((t) => t.to === i.to))
      const at = before ? target.items.findIndex((t) => t.to === before.to) + 1 : 0
      target.items.splice(at, 0, item)
    })
  }

  return result.filter((group) => !hiddenGroups.has(group.labelKey))
}
