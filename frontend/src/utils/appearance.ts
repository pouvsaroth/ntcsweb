import { ref } from 'vue'

import { SUPPORTED_LOCALES, i18n, setLocale, type Locale } from '@/i18n'
import { setClockFormat, type ClockFormat } from '@/utils/date'

/**
 * A person's own look of the admin app (My account → Preferences): theme,
 * accent colour and clock. Kept the way SmartGPS keeps them:
 *
 * - applied at once from this browser's copy (localStorage), so a reload
 *   does not open in the wrong theme and then change;
 * - saved to the account (users.preferences), so they follow the person to
 *   any device — adoptPreferences() takes the account's on sign-in;
 * - another open tab of this browser follows (the storage event).
 *
 * Dark mode is the neutral palette turned around (main.css,
 * `[data-theme='dark']`); the accent is the whole primary-50…900 scale
 * built from one colour and set on the root element.
 */

export type ThemeMode = 'system' | 'light' | 'dark'
export type { ClockFormat }

export interface UserPreferences {
  locale?: string
  theme?: ThemeMode
  /** #rrggbb, or null = the school's colour. */
  accent?: string | null
  clock?: ClockFormat
}

/** The school's own colour (main.css --color-primary-*), hand-tuned. */
export const SCHOOL_ACCENT = '#f5a800'
const SCHOOL_SCALE = ['#fffbeb', '#fff3c4', '#ffe58a', '#ffd24d', '#ffc01a', '#f5a800', '#d99400', '#b37a00', '#8c6000', '#664600']

/**
 * Ready-made colours after the school's; any other is picked from the
 * rainbow swatch. Whatever is chosen, accentScale() adjusts it only as far
 * as it takes to stay readable (SmartGPS core/accent.ts does the same).
 */
export const ACCENT_PRESETS = [
  '#38bdf8', '#60a5fa', '#818cf8', '#a78bfa', '#c084fc', '#e879f9', '#f472b6', '#fb7185',
  '#f87171', '#fb923c', '#facc15', '#a3e635', '#4ade80', '#34d399', '#2dd4bf', '#22d3ee', '#94a3b8',
] as const

const STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900] as const
const STORAGE_KEY = 'ntcsweb.appearance'

export const themeMode = ref<ThemeMode>('system')
export const accentColor = ref<string | null>(null)
export const clockFormat = ref<ClockFormat>('24')
/** The theme actually showing (system resolved). */
export const isDark = ref(false)

function parseHex(hex: string | null | undefined): [number, number, number] | null {
  const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex ?? '')
  return m ? [parseInt(m[1]!, 16), parseInt(m[2]!, 16), parseInt(m[3]!, 16)] : null
}

function toHex(rgb: number[]): string {
  return '#' + rgb.map((c) => Math.round(c).toString(16).padStart(2, '0')).join('')
}

function mix(a: number[], b: number[], t: number): number[] {
  return a.map((c, i) => c + (b[i]! - c) * t)
}

const WHITE = [255, 255, 255]
const BLACK = [0, 0, 0]
/** The navy every primary surface carries its text in (text-secondary-900). */
const NAVY = [12, 18, 56]

/** WCAG 2 relative luminance. */
function luminance(rgb: number[]): number {
  const [r, g, b] = rgb.map((c) => {
    const v = c / 255
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
  }) as [number, number, number]
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

function contrast(a: number[], b: number[]): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x) as [number, number]
  return (hi + 0.05) / (lo + 0.05)
}

/** Moved toward `to` just far enough to reach `ratio` against `against` (a colour that already does is kept as is). */
function reach(rgb: number[], to: number[], against: number[], ratio: number): number[] {
  for (let t = 0; t <= 1; t += 0.02) {
    const c = mix(rgb, to, t)
    if (contrast(c, against) >= ratio) return c
  }
  return to
}

/**
 * The button colour from the chosen one: buttons and the active menu draw
 * navy text on primary-500/600, so a dark choice (navy, deep red…) is
 * lightened just enough for that text to read (4.5:1).
 */
function readableBase(hex: string): number[] {
  const rgb = parseHex(hex) ?? parseHex(SCHOOL_ACCENT)!
  return reach(rgb, WHITE, NAVY, 4.8)
}

/**
 * 50…900 from one colour: pale tints toward white; 600 for buttons (navy
 * text still reads); 700…900 deep enough to read as text on white.
 */
export function accentScale(hex: string): string[] {
  const chosen = parseHex(hex) ?? parseHex(SCHOOL_ACCENT)!
  const base = readableBase(hex)
  const tints = [0.92, 0.8, 0.62, 0.42, 0.2] // 50…400
  // Text shades start from the colour as chosen, so a deep one stays deep.
  return [
    ...tints.map((t) => toHex(mix(base, WHITE, t))),
    toHex(base),
    toHex(reach(mix(base, BLACK, 0.1), WHITE, NAVY, 4.5)),
    toHex(reach(chosen, BLACK, WHITE, 4.5)),
    toHex(reach(chosen, BLACK, WHITE, 6)),
    toHex(reach(chosen, BLACK, WHITE, 8)),
  ]
}

/**
 * The scale on a dark page: the pale tints behind highlighted rows and pills
 * (50…300) become deep ones, the deep text on them (700…900) becomes light,
 * and the button colour (500/600) stays vivid.
 */
function darkScale(hex: string): string[] {
  const base = readableBase(hex)
  const page = [11, 18, 32] // --dark page, main.css
  const white = WHITE
  return [
    toHex(mix(base, page, 0.82)),
    toHex(mix(base, page, 0.7)),
    toHex(mix(base, page, 0.55)),
    toHex(mix(base, page, 0.35)),
    toHex(mix(base, page, 0.15)),
    toHex(base),
    toHex(mix(base, white, 0.08)),
    toHex(mix(base, white, 0.4)),
    toHex(mix(base, white, 0.6)),
    toHex(mix(base, white, 0.78)),
  ]
}

function systemDark(): boolean {
  return typeof window !== 'undefined' && window.matchMedia?.('(prefers-color-scheme: dark)').matches === true
}

function apply(): void {
  const root = document.documentElement
  isDark.value = themeMode.value === 'dark' || (themeMode.value === 'system' && systemDark())
  root.dataset.theme = isDark.value ? 'dark' : 'light'
  root.style.colorScheme = isDark.value ? 'dark' : 'light'

  const shown = isDark.value ? darkScale(accentColor.value ?? SCHOOL_ACCENT) : accentColor.value ? accentScale(accentColor.value) : SCHOOL_SCALE
  STEPS.forEach((step, i) => {
    if (!accentColor.value && !isDark.value) root.style.removeProperty(`--color-primary-${step}`)
    else root.style.setProperty(`--color-primary-${step}`, shown[i]!)
  })
  setClockFormat(clockFormat.value)
}

function persist(): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ theme: themeMode.value, accent: accentColor.value, clock: clockFormat.value }))
  } catch {
    // Storage blocked: the choice still lasts for this page.
  }
}

function take(prefs: UserPreferences): void {
  if (prefs.theme && ['system', 'light', 'dark'].includes(prefs.theme)) themeMode.value = prefs.theme
  if (prefs.accent !== undefined) accentColor.value = prefs.accent && parseHex(prefs.accent) ? prefs.accent.toLowerCase() : null
  if (prefs.clock === '12' || prefs.clock === '24') clockFormat.value = prefs.clock
}

function readStored(): UserPreferences {
  try {
    return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}') as UserPreferences
  } catch {
    return {}
  }
}

let started = false

/** Once, before the app mounts: this browser's copy, the system theme, other tabs. */
export function initAppearance(): void {
  if (started) return
  started = true
  take(readStored())
  apply()
  window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', () => {
    if (themeMode.value === 'system') apply()
  })
  window.addEventListener('storage', (e) => {
    if (e.key !== STORAGE_KEY && e.key !== null) return
    take(readStored())
    apply()
  })
}

/** The account's choices, on sign-in or /auth/me: they win over this browser's. */
export function adoptPreferences(prefs: UserPreferences | null | undefined): void {
  // Admin app only: the public website keeps its own look.
  if (!started || !prefs) return
  take(prefs)
  apply()
  persist()
  const locale = prefs.locale
  if (locale && (SUPPORTED_LOCALES as readonly string[]).includes(locale) && i18n.global.locale.value !== locale) {
    setLocale(locale as Locale)
  }
}

/** Every choice as it stands now (the language is sent only when picked). */
export function currentPreferences(): UserPreferences {
  return { theme: themeMode.value, accent: accentColor.value, clock: clockFormat.value }
}

/** Chosen on this device right now (before the save answers). */
export function previewPreferences(prefs: UserPreferences): void {
  if (!started) return
  take(prefs)
  apply()
  persist()
}
