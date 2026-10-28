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
 * The six other colours on offer. Each is light enough that the navy text
 * drawn on primary surfaces (buttons, active menu) still reads clearly.
 */
export const ACCENT_PRESETS = [
  { hex: '#38bdf8', key: 'sky' },
  { hex: '#34d399', key: 'green' },
  { hex: '#2dd4bf', key: 'teal' },
  { hex: '#a78bfa', key: 'violet' },
  { hex: '#fb7185', key: 'rose' },
  { hex: '#fb923c', key: 'orange' },
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

/** 50…900 from one colour: lighter tints toward white, deeper shades toward black. */
export function accentScale(hex: string): string[] {
  const base = parseHex(hex)
  if (!base) return SCHOOL_SCALE
  const white = [255, 255, 255]
  const black = [0, 0, 0]
  const tints = [0.92, 0.8, 0.62, 0.42, 0.2] // 50…400
  const shades = [0.12, 0.28, 0.44, 0.58] // 600…900
  return [...tints.map((t) => toHex(mix(base, white, t))), hex.toLowerCase(), ...shades.map((t) => toHex(mix(base, black, t)))]
}

/**
 * The scale on a dark page: the pale tints behind highlighted rows and pills
 * (50…300) become deep ones, the deep text on them (700…900) becomes light,
 * and the button colour (500/600) stays vivid.
 */
function darkScale(hex: string): string[] {
  const base = parseHex(hex) ?? parseHex(SCHOOL_ACCENT)!
  const page = [11, 18, 32] // --dark page, main.css
  const white = [255, 255, 255]
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

/** Chosen on this device right now (before the save answers). */
export function previewPreferences(prefs: UserPreferences): void {
  if (!started) return
  take(prefs)
  apply()
  persist()
}
