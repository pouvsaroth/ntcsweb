import en from '@/i18n/locales/en'
import { adminNav } from '@/router/adminNav'

/**
 * Settings > Language > Translation's tree: every piece of the admin app's
 * text, arranged the way the sidebar is — menu group › menu item (› its
 * tabs) › the words on that page — then "Common" (Save, Cancel, ...) and
 * "Other" for anything no menu claims, so no word is ever missing.
 *
 * Which words belong to a menu item comes from its locale namespace: by
 * default the page's own path (/admin/asset-issues → admin.assetIssues),
 * or the explicit sections below where a page has tabs or its namespace is
 * named differently. Each word is listed once, under the first menu that
 * claims it.
 */
export interface TranslationNode {
  id: string
  /** Shown through t(), so the tree itself shows the school's own words. */
  labelKey?: string
  /** A plain label, for the "Other" groups named after their namespace. */
  label?: string
  /** Dotted message keys listed directly under this node. */
  words: string[]
  children: TranslationNode[]
}

interface Section {
  labelKey: string
  prefixes: string[]
}

/** The namespaces the admin app shows — the public website and sign-in page don't apply a school's words, so their text isn't listed. */
const ADMIN_APP_NAMESPACES = [
  'admin', 'adminNav', 'studentNav', 'common', 'auth', 'notifications',
  'leaveRequest', 'resignationRequest', 'makeUpClassRequest', 'monthlyPaymentAlert',
]

/** Menu items whose words aren't simply their path's namespace — one entry per tab where the page has tabs. */
const MENU_SECTIONS: Record<string, Section[] | string[]> = {
  'adminNav.items.programs': [
    { labelKey: 'adminNav.items.academicPrograms', prefixes: ['admin.academicPrograms'] },
    { labelKey: 'adminNav.items.academicYears', prefixes: ['admin.academicYears'] },
    { labelKey: 'adminNav.items.coursePackages', prefixes: ['admin.coursePackages'] },
    { labelKey: 'adminNav.items.books', prefixes: ['admin.books'] },
    { labelKey: 'adminNav.items.bookCategories', prefixes: ['admin.bookCategories'] },
  ],
  'adminNav.items.studyBuilding': [
    { labelKey: 'adminNav.items.buildings', prefixes: ['admin.buildings'] },
    { labelKey: 'adminNav.items.classrooms', prefixes: ['admin.classrooms', 'admin.classroomTables'] },
  ],
  'adminNav.items.classes': ['admin.classes', 'admin.classStudents'],
  'adminNav.items.examination': [
    { labelKey: 'adminNav.items.exams', prefixes: ['admin.exams'] },
    { labelKey: 'adminNav.items.grades', prefixes: ['admin.grades'] },
    { labelKey: 'adminNav.items.makeUpExam', prefixes: ['admin.makeUpExam'] },
    { labelKey: 'adminNav.items.certificate', prefixes: ['admin.examCertificate'] },
  ],
  'adminNav.items.organizationManagement': [
    { labelKey: 'admin.organization.tabs.school', prefixes: ['admin.school'] },
    { labelKey: 'admin.organization.tabs.department', prefixes: ['admin.departments'] },
    { labelKey: 'admin.organization.tabs.position', prefixes: ['admin.positions'] },
    { labelKey: 'admin.translations.organizationLists', prefixes: ['admin.organization'] },
  ],
  'adminNav.items.requestLeave': ['admin.myRequests', 'leaveRequest', 'resignationRequest', 'makeUpClassRequest'],
  'adminNav.items.billingDashboard': ['admin.billing', 'monthlyPaymentAlert'],
  'adminNav.items.accountingDashboard': ['admin.accountingDashboard'],
  'adminNav.items.assetDashboard': ['admin.assetDashboard'],
  'adminNav.items.notifications': ['notifications'],
  'adminNav.items.languages': [
    { labelKey: 'admin.languages.tabLanguage', prefixes: ['admin.languages'] },
    { labelKey: 'admin.languages.tabTranslation', prefixes: ['admin.translations'] },
  ],
  'adminNav.items.lookupCategories': ['admin.lookupCategories', 'admin.lookupValues'],
  'adminNav.items.approvals': ['admin.approvals', 'admin.studentRegistrations', 'admin.leaveRequests'],
  'adminNav.items.approvalGroups': ['admin.approvalGroups'],
  'adminNav.items.flowSetting': ['admin.approvalFlows'],
}

/** A student's own pages, reached from their dashboard rather than the sidebar. */
const STUDENT_DASHBOARD = ['admin.myExamApplications', 'admin.myFeedback']

const COMMON = ['common', 'admin.comingSoon', 'auth']

type MessageTree = { [key: string]: string | MessageTree }

function flatten(tree: MessageTree, prefix: string, out: string[]): void {
  for (const [key, value] of Object.entries(tree)) {
    const path = prefix ? `${prefix}.${key}` : key
    if (typeof value === 'string') out.push(path)
    else flatten(value, path, out)
  }
}

/** Every key of the admin app's text, in locale-file order. */
export const translationKeys: string[] = (() => {
  const out: string[] = []
  const messages = en as unknown as MessageTree
  for (const namespace of ADMIN_APP_NAMESPACES) {
    const node = messages[namespace]
    if (node && typeof node === 'object') flatten(node, namespace, out)
  }
  return out
})()

function camel(segment: string): string {
  return segment.replace(/-([a-z])/g, (_match, letter: string) => letter.toUpperCase())
}

export function buildTranslationTree(): TranslationNode[] {
  const claimed = new Set<string>()
  const known = new Set(translationKeys)

  const claim = (prefixes: string[]): string[] => {
    const words = translationKeys.filter((key) => !claimed.has(key) && prefixes.some((prefix) => key === prefix || key.startsWith(`${prefix}.`)))
    words.forEach((key) => claimed.add(key))
    return words
  }
  const claimKey = (key: string): string[] => (known.has(key) && !claimed.has(key) ? (claimed.add(key), [key]) : [])

  const tree: TranslationNode[] = adminNav.map((group) => ({
    id: group.labelKey,
    labelKey: group.labelKey,
    words: claimKey(group.labelKey),
    children: group.items
      .filter((item) => item.to)
      .map((item) => {
        const id = `${group.labelKey}/${item.labelKey}/${item.to}`
        const sections = MENU_SECTIONS[item.labelKey]
        const menuName = claimKey(item.labelKey)

        if (sections && typeof sections[0] === 'object') {
          return {
            id,
            labelKey: item.labelKey,
            words: menuName,
            children: (sections as Section[]).map((section) => ({
              id: `${id}/${section.labelKey}`,
              labelKey: section.labelKey,
              words: [...claimKey(section.labelKey), ...claim(section.prefixes)],
              children: [],
            })),
          }
        }

        const path = item.to!.split('?')[0]!.replace(/^\/admin\/?/, '')
        const prefixes = (sections as string[] | undefined) ?? (item.studentOnly && path === '' ? STUDENT_DASHBOARD : [`admin.${camel(path.split('/').pop() || 'dashboard')}`])
        return { id, labelKey: item.labelKey, words: [...menuName, ...claim(prefixes)], children: [] }
      }),
  }))

  tree.push({ id: 'common', labelKey: 'admin.translations.common', words: claim(COMMON), children: [] })

  // Whatever is left, one group per namespace (admin.programs, adminNav.items, ...).
  const others = new Map<string, string[]>()
  for (const key of translationKeys) {
    if (claimed.has(key)) continue
    const parts = key.split('.')
    const namespace = parts.slice(0, parts[0] === 'admin' || parts[0] === 'adminNav' ? 2 : 1).join('.')
    others.set(namespace, [...(others.get(namespace) ?? []), key])
  }
  if (others.size > 0) {
    tree.push({
      id: 'other',
      labelKey: 'admin.translations.other',
      words: [],
      children: [...others].map(([namespace, words]) => ({ id: `other/${namespace}`, label: namespace, words, children: [] })),
    })
  }

  return tree
}

/** The `{name}` placeholders a message fills in. */
export function placeholders(message: string): string[] {
  return [...new Set([...message.matchAll(/\{\s*(\w+)\s*\}/g)].map((match) => match[1]!))]
}
