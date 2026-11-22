/**
 * The Menu layout editor's model (pages/MenuLayoutPage.vue), kept apart from
 * the page so it can be checked on its own. The console reads the saved
 * layout with router/menuLayout.ts; build() lists the menu the same way.
 */
import { LAYOUT_GROUPS, MENU_LAYOUT_LANGS, orderTabs, TAB_BY_PATH, TAB_CATALOG, type MenuLayout, type MenuLayoutNames } from '@/router/menuLayout'

export type Lang = (typeof MENU_LAYOUT_LANGS)[number]
export type Names = Record<Lang, string>
export const LANG_LABELS: Record<Lang, string> = { en: 'English', km: 'ខ្មែរ', zh: '中文', ja: '日本語', ko: '한국어' }

export interface EdItem {
  uid: string
  to: string
  /** The standard group it comes from, or 'tab' for a tab taken out of its page. */
  from: string
  labelKey: string
  icon?: string
  names: Names
  hidden: boolean
  open: boolean
}
export interface EdGroup {
  uid: string
  key: string
  /** The standard group's label key; null for a new group. */
  labelKey: string | null
  icon: string
  names: Names
  hidden: boolean
  open: boolean
  items: EdItem[]
}

let seq = 0
export const uid = () => `u${++seq}`

/** A new group; its key stays the same once saved. */
export function newGroup(): EdGroup {
  return { uid: uid(), key: `custom:g${Date.now().toString(36)}${seq}`, labelKey: null, icon: 'collection', names: blankNames(), hidden: false, open: true, items: [] }
}
export const blankNames = (): Names => ({ en: '', km: '', zh: '', ja: '', ko: '' })
const fillNames = (names: MenuLayoutNames | undefined): Names => ({ ...blankNames(), ...Object.fromEntries(Object.entries(names ?? {}).filter(([, v]) => v)) })
export const stdGroup = (key: string) => LAYOUT_GROUPS.find((g) => g.labelKey === key)

function stdItem(groupKey: string, item: { to: string; labelKey: string; icon?: string }, names?: MenuLayoutNames, hidden = false): EdItem {
  return { uid: uid(), to: item.to, from: groupKey, labelKey: item.labelKey, icon: item.icon, names: fillNames(names), hidden, open: false }
}
export function tabItem(to: string, names?: MenuLayoutNames, hidden = false): EdItem | null {
  const tab = TAB_BY_PATH.get(to)
  return tab ? { uid: uid(), to, from: 'tab', labelKey: tab.labelKey, icon: 'template', names: fillNames(names), hidden, open: false } : null
}

/** The editor's full list: the layout's groups and items, then everything it leaves out in its standard place (as the console does). */
export function build(layout: MenuLayout | null): EdGroup[] {
  const used = new Set<string>()
  const groups: EdGroup[] = []
  for (const lg of layout?.groups ?? []) {
    const base = stdGroup(lg.key)
    if ((!base && !lg.key.startsWith('custom:')) || groups.some((g) => g.key === lg.key)) continue
    const items: EdItem[] = []
    for (const li of lg.items) {
      if (li.from === 'tab') {
        const item = used.has(`tab|${li.to}`) ? null : tabItem(li.to, li.names, li.hidden)
        if (!item) continue
        used.add(`tab|${li.to}`)
        items.push(item)
        continue
      }
      const from = li.from ? stdGroup(li.from) : LAYOUT_GROUPS.find((g) => g.items.some((i) => i.to === li.to))
      const found = from?.items.find((i) => i.to === li.to)
      if (!from || !found?.to || used.has(`${from.labelKey}|${found.to}`)) continue
      used.add(`${from.labelKey}|${found.to}`)
      items.push(stdItem(from.labelKey, { ...found, to: found.to }, li.names, li.hidden))
    }
    groups.push({ uid: uid(), key: lg.key, labelKey: base?.labelKey ?? null, icon: lg.icon || base?.icon || 'collection', names: fillNames(lg.names), hidden: !!lg.hidden, open: false, items })
  }
  LAYOUT_GROUPS.forEach((group, index) => {
    if (groups.some((g) => g.key === group.labelKey)) return
    const before = LAYOUT_GROUPS.slice(0, index).reverse().find((b) => groups.some((g) => g.key === b.labelKey))
    const at = before ? groups.findIndex((g) => g.key === before.labelKey) + 1 : 0
    groups.splice(at, 0, { uid: uid(), key: group.labelKey, labelKey: group.labelKey, icon: group.icon || 'collection', names: blankNames(), hidden: false, open: false, items: [] })
  })
  for (const group of LAYOUT_GROUPS) {
    const target = groups.find((g) => g.key === group.labelKey)!
    group.items.forEach((item, index) => {
      if (!item.to || used.has(`${group.labelKey}|${item.to}`)) return
      const before = group.items.slice(0, index).reverse().find((b) => b.to && target.items.some((x) => x.to === b.to))
      const at = before ? target.items.findIndex((x) => x.to === before.to) + 1 : 0
      target.items.splice(at, 0, stdItem(group.labelKey, { ...item, to: item.to }))
    })
  }
  return groups
}

export function buildTabs(layout: MenuLayout | null): Record<string, string[]> {
  return Object.fromEntries(TAB_CATALOG.map((b) => [b.key, orderTabs(b.tabs, layout).map((tab) => tab.to)]))
}

export const trimmed = (names: Names): MenuLayoutNames | undefined => {
  const out = Object.fromEntries(Object.entries(names).map(([k, v]) => [k, v.trim()]).filter(([, v]) => v))
  return Object.keys(out).length ? out : undefined
}

const shape = (groups: EdGroup[]) => JSON.stringify(groups.map((g) => ({ key: g.key, items: g.items.map((i) => `${i.from}|${i.to}`) })))
const STANDARD_SHAPE = shape(build(null))

/** The layout to save; null when it is just the standard menu. */
export function toLayout(groups: EdGroup[], tabOrder: Record<string, string[]>): MenuLayout | null {
  const layout: MenuLayout = {
    groups: groups.map((g) => {
      const base = g.labelKey ? stdGroup(g.labelKey) : undefined
      const names = trimmed(g.names)
      return {
        key: g.key,
        ...(names ? { names } : {}),
        ...(!base || g.icon !== (base.icon || 'collection') ? { icon: g.icon } : {}),
        ...(g.hidden ? { hidden: true } : {}),
        items: g.items.map((i) => {
          const itemNames = trimmed(i.names)
          return { to: i.to, from: i.from, ...(itemNames ? { names: itemNames } : {}), ...(i.hidden ? { hidden: true } : {}) }
        }),
      }
    }),
  }
  const tabs = Object.fromEntries(
    TAB_CATALOG.filter((b) => tabOrder[b.key]?.join('|') !== b.tabs.map((tab) => tab.to).join('|')).map((b) => [b.key, tabOrder[b.key]!]),
  )
  if (Object.keys(tabs).length) layout.tabs = tabs
  const plain = layout.groups.every((g) => !g.names && !g.icon && !g.hidden && g.items.every((i) => !i.names && !i.hidden))
  return plain && shape(groups) === STANDARD_SHAPE && !layout.tabs ? null : layout
}
