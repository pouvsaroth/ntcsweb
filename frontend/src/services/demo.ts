import { apiPost } from '@/services/http'

/** "Try the demo" on the website's Plans page (docs/backoffice/stage-12-demo.md). */
export type DemoRole = 'director' | 'teacher' | 'student'

export interface DemoForm {
  name: string
  phone: string
  email: string
  role: DemoRole
  locale: string
  /** Honeypot: people never see it, so it stays empty. */
  website: string
}

export interface DemoStart {
  token: string
  role: DemoRole
  name: string
  expires_at: string
  /** The console address that signs this browser in, once, for two minutes. */
  sign_in_url: string
}

export const demoService = {
  start: (form: DemoForm) =>
    apiPost<DemoStart>('/public/demo', {
      ...form,
      phone: form.phone.trim() || null,
      email: form.email.trim() || null,
    }),
  resume: (token: string) => apiPost<DemoStart>(`/public/demo/${token}/enter`),
}

/** The visitor's last demo in this browser, for "Continue my demo". */
const KEY = 'ntcs_demo'

export type SavedDemo = Pick<DemoStart, 'token' | 'role' | 'name' | 'expires_at'>

export function savedDemo(): SavedDemo | null {
  try {
    const raw = window.localStorage.getItem(KEY)
    if (!raw) return null
    const demo = JSON.parse(raw) as SavedDemo
    return demo?.token && new Date(demo.expires_at).getTime() > Date.now() ? demo : null
  } catch {
    return null
  }
}

export function saveDemo(demo: DemoStart): void {
  try {
    window.localStorage.setItem(KEY, JSON.stringify({ token: demo.token, role: demo.role, name: demo.name, expires_at: demo.expires_at }))
  } catch {
    // Private window or blocked storage: the demo still works, just not "Continue" later.
  }
}

export function forgetDemo(): void {
  try {
    window.localStorage.removeItem(KEY)
  } catch {
    // Nothing to do.
  }
}
