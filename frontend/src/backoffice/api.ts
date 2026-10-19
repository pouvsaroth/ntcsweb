import axios, { type AxiosRequestConfig, isAxiosError } from 'axios'

import { ApiRequestError, type ApiError, type ApiSuccess, type LengthAwarePaginationMeta, type PaginatedResult } from '@/types/api'

/**
 * The back office's own client for /api/v1/platform/* — deliberately not the
 * school app's `http` (services/http.ts), which carries X-Tenant and the
 * acting-school machinery. Staff are not inside a school, so nothing here
 * ever names one.
 *
 * Session cookie + XSRF, like the school SPA: `primeCsrf()` before the first
 * write, and the server's `web` group checks X-XSRF-TOKEN.
 */
const http = axios.create({
  baseURL: '/api/v1/platform',
  withCredentials: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: { Accept: 'application/json' },
})

let onUnauthenticated: (() => void) | null = null

/** The router registers this: a 401 from anything but sign-in means the session ended. */
export function setUnauthenticatedHandler(handler: () => void): void {
  onUnauthenticated = handler
}

export async function primeCsrf(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

function toError(error: unknown): ApiRequestError {
  if (isAxiosError<ApiError>(error)) {
    const status = error.response?.status ?? 0
    const body = error.response?.data
    if (status === 401 && !String(error.config?.url ?? '').startsWith('/auth/')) onUnauthenticated?.()
    if (body && typeof body === 'object' && 'success' in body) {
      return new ApiRequestError(body.message, status, body.error?.code, body.errors)
    }
    return new ApiRequestError(error.message, status)
  }
  return new ApiRequestError('An unexpected error occurred.', 0)
}

async function call<T>(config: AxiosRequestConfig): Promise<ApiSuccess<T>> {
  try {
    const response = await http.request<ApiSuccess<T>>(config)
    return response.data
  } catch (error) {
    throw toError(error)
  }
}

const get = async <T>(url: string, params?: unknown) => (await call<T>({ method: 'get', url, params })).data
const send = async <T>(method: 'post' | 'put' | 'patch' | 'delete', url: string, data?: unknown) =>
  (await call<T>({ method, url, data }))?.data

async function page<T>(url: string, params: unknown): Promise<PaginatedResult<T>> {
  const body = await call<T[]>({ method: 'get', url, params })
  return { data: body.data, pagination: (body.meta?.pagination ?? null) as LengthAwarePaginationMeta }
}

// ── Shapes (mirror app/Platform/Http/Resources) ─────────────────────────────

export interface StaffRoleRef {
  id: number
  name: string
  is_owner: boolean
}

export interface Staff {
  id: number
  name: string
  email: string
  status: 'active' | 'disabled'
  must_change_password: boolean
  two_factor_enabled: boolean
  last_login_at: string | null
  roles: StaffRoleRef[]
  created_at: string | null
  [key: string]: unknown
}

export interface Role {
  id: number
  name: string
  description: string | null
  is_owner: boolean
  permissions: string[]
  staff_count?: number
  updated_at: string | null
  [key: string]: unknown
}

export interface PermissionNode {
  key: string
  label: string
  label_km: string | null
  children: PermissionNode[]
}

export interface AuditLog {
  id: number
  action: string
  staff: { id: number; name: string } | null
  target_type: string | null
  target_id: number | null
  tenant_id: number | null
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  ip_address: string | null
  user_agent: string | null
  created_at: string
  [key: string]: unknown
}

export interface Session {
  staff: Staff
  permissions: string[]
  must_change_password: boolean
  recovery_codes_left: number
}

export interface ListQuery {
  page: number
  per_page: number
  search?: string
  sort?: string
  filter?: Record<string, string>
}

// ── Sign-in ──────────────────────────────────────────────────────────────────

export const authApi = {
  /** 'off': Settings → Security asks for no code, and the reply is already the signed-in session. */
  login: (email: string, password: string) =>
    send<{ two_factor: 'required' | 'setup' } | ({ two_factor: 'off' } & Session)>('post', '/auth/login', { email, password }),
  twoFactor: (code: string) => send<Session>('post', '/auth/two-factor', { code }),
  setup: () => send<{ secret: string; otpauth_uri: string }>('post', '/auth/two-factor/setup'),
  confirm: (code: string) => send<Session & { recovery_codes: string[] }>('post', '/auth/two-factor/confirm', { code }),
  session: () => get<Session>('/auth/session'),
  logout: () => send<void>('post', '/auth/logout'),
  changePassword: (body: { current_password: string; password: string; password_confirmation: string }) =>
    send<Session>('put', '/auth/password', body),
  regenerateRecoveryCodes: (currentPassword: string) =>
    send<{ recovery_codes: string[] }>('post', '/auth/recovery-codes', { current_password: currentPassword }),
}

// ── Staff, roles, audit ─────────────────────────────────────────────────────

export const staffApi = {
  list: (query: ListQuery) => page<Staff>('/staff', query),
  create: (body: { name: string; email: string; roles: number[] }) =>
    send<{ staff: Staff; temporary_password: string }>('post', '/staff', body),
  update: (id: number, body: Partial<{ name: string; status: Staff['status']; roles: number[] }>) =>
    send<Staff>('patch', `/staff/${id}`, body),
  resetTwoFactor: (id: number) => send<Staff>('post', `/staff/${id}/reset-two-factor`),
  resetPassword: (id: number) => send<{ staff: Staff; temporary_password: string }>('post', `/staff/${id}/reset-password`),
}

export const rolesApi = {
  list: () => get<Role[]>('/roles'),
  permissions: () => get<PermissionNode[]>('/permissions'),
  create: (body: { name: string; description: string | null; permissions: string[] }) => send<Role>('post', '/roles', body),
  update: (id: number, body: { name: string; description: string | null; permissions: string[] }) =>
    send<Role>('put', `/roles/${id}`, body),
  remove: (id: number) => send<void>('delete', `/roles/${id}`),
  copy: (id: number, name: string) => send<Role>('post', `/roles/${id}/copy`, { name }),
}

export const auditApi = {
  list: (query: ListQuery & { from?: string; to?: string }) => page<AuditLog>('/audit-logs', query),
}

// ── Schools (Stage 2) ────────────────────────────────────────────────────────

export type SchoolStatus = 'provisioning' | 'failed' | 'active' | 'suspended' | 'archived'

export interface SchoolDomain {
  id: number
  hostname: string
  is_primary: boolean
  verified: boolean
  verified_at: string | null
  txt_name: string | null
  txt_value: string | null
}

export interface ProvisioningStep {
  attempt: number
  step: 'database' | 'seed' | 'health' | 'activate' | 'owner'
  status: 'ok' | 'failed'
  message: string | null
  duration_ms: number
  at: string | null
}

export interface School {
  id: number
  name: string
  name_en: string | null
  slug: string
  status: SchoolStatus
  subdomain: string
  database: string
  domain_target: string
  placement: Placement
  email: string | null
  phone: string | null
  address: string | null
  timezone: string
  locale: string
  default_currency: 'USD' | 'KHR'
  owner: { id: number; name: string; email: string; accepted: boolean } | null
  status_detail: string | null
  provisioning_attempts: number
  activated_at: string | null
  invite_sent_at: string | null
  created_at: string | null
  domains?: SchoolDomain[]
  provisioning_log?: ProvisioningStep[]
  status_history?: { from: string | null; to: string; reason: string | null; staff: string | null; at: string | null }[]
  billing?: BillingSummary | null
  /** The Schools list dot (stage 6a); only for staff with platform.support.read. */
  health?: HealthDot | null
  [key: string]: unknown
}

// ── Health (stage 6a) ───────────────────────────────────────────────────────

export type HealthState = 'ok' | 'warn' | 'bad' | 'off'
export type HealthKey = 'status' | 'database' | 'backup' | 'plan' | 'students' | 'users' | 'sign_in' | 'failed_jobs'

export interface HealthDot {
  state: Exclude<HealthState, 'off'>
  /** The lines that are warn or bad. */
  reasons: HealthKey[]
}

/** One health line; which fields are set depends on the key. */
export interface HealthLine {
  key: HealthKey
  state: HealthState
  // status
  status?: string
  since?: string | null
  reason?: string | null
  by?: string | null
  // database
  mode?: PlacementMode
  database?: string | null
  server?: string | null
  exists?: boolean | null
  size_bytes?: number | null
  pending_migrations?: number | null
  error?: string | null
  // backup, sign_in
  at?: string | null
  copied?: boolean
  copy_error?: string | null
  schedule_enabled?: boolean
  // plan
  flag?: BillingFlag
  subscription?: Subscription | null
  // students, users
  active?: number | null
  limit?: number | null
  unavailable?: boolean
  total?: number
  active_days?: number
  // sign_in
  name?: string | null
  quiet_days?: number
  // failed_jobs
  count?: number
  days?: number
  latest?: { message: string; at: string } | null
}

export interface SchoolHealthReport {
  state: Exclude<HealthState, 'off'>
  checked_at: string
  lines: HealthLine[]
}

export type PlacementMode = 'shared' | 'dedicated' | 'separate'

/** Where a school's data lives. A separate server's password is never sent back. */
export interface Placement {
  mode: PlacementMode
  database: string
  host: string | null
  port: number | null
  username: string | null
  sslmode: string | null
}

/** A separate Postgres server, as staff type it in. */
export interface ServerLogin {
  host: string
  port: number
  database: string
  username: string
  password: string
  sslmode: string
}

export interface ConnectionTest {
  ok: boolean
  message: string
  superuser?: boolean
  can_create?: boolean
}

export interface SchoolMove {
  id: number
  status: 'queued' | 'running' | 'done' | 'failed'
  from: Omit<Placement, 'mode'> & { mode: PlacementMode }
  to: Omit<Placement, 'mode'> & { mode: PlacementMode }
  steps: { step: string; status: 'ok' | 'failed'; message: string | null; ms: number }[]
  counts: { source?: Record<string, number>; copy?: Record<string, number>; written?: Record<string, number> } | null
  error: string | null
  staff: string | null
  old_copy_removed_at: string | null
  created_at: string | null
}

export interface NewSchool {
  name: string
  name_en: string | null
  slug: string
  owner_name: string
  owner_email: string
  timezone?: string
  locale?: string
  default_currency?: 'USD' | 'KHR'
  placement?: PlacementMode
  server?: ServerLogin
}

export type SchoolProfile = Partial<Pick<School, 'name' | 'name_en' | 'email' | 'phone' | 'address' | 'timezone' | 'locale' | 'default_currency'>>

export const schoolsApi = {
  list: (query: ListQuery) => page<School>('/schools', query),
  show: (id: number) => get<School>(`/schools/${id}`),
  health: (id: number) => get<SchoolHealthReport>(`/schools/${id}/health`),
  create: (body: NewSchool) => send<School>('post', '/schools', body),
  update: (id: number, body: SchoolProfile) => send<School>('patch', `/schools/${id}`, body),
  retry: (id: number) => send<School>('post', `/schools/${id}/retry`),
  suspend: (id: number, reason: string) => send<School>('post', `/schools/${id}/suspend`, { reason }),
  reactivate: (id: number) => send<School>('post', `/schools/${id}/reactivate`),
  archive: (id: number, reason: string) => send<School>('post', `/schools/${id}/archive`, { reason }),
  resendInvite: (id: number) => send<null>('post', `/schools/${id}/owner/invite`),
  testConnection: (server: ServerLogin) => send<ConnectionTest>('post', '/schools/test-connection', server),
  moves: (id: number) => get<SchoolMove[]>(`/schools/${id}/moves`),
  move: (id: number, placement: PlacementMode, server?: ServerLogin) => send<SchoolMove>('post', `/schools/${id}/moves`, { placement, server }),
  removeOldCopy: (moveId: number) => send<SchoolMove>('post', `/school-moves/${moveId}/remove-old-copy`),
  inviteLink: (id: number) => send<{ url: string; expires_in_days: number }>('post', `/schools/${id}/owner/invite-link`),
  changeOwner: (id: number, name: string, email: string) => send<null>('put', `/schools/${id}/owner`, { name, email }),
  addDomain: (id: number, hostname: string) => send<{ id: number; hostname: string }>('post', `/schools/${id}/domains`, { hostname }),
  verifyDomain: (id: number, domainId: number) => send<{ verified: boolean }>('post', `/schools/${id}/domains/${domainId}/verify`),
  primaryDomain: (id: number, domainId: number) => send<null>('post', `/schools/${id}/domains/${domainId}/primary`),
  removeDomain: (id: number, domainId: number) => send<void>('delete', `/schools/${id}/domains/${domainId}`),
}

// ── Billing (Stage 5) ────────────────────────────────────────────────────────

export interface PlanModule {
  code: string
  label: string
  label_km: string
  groups: string[]
}

export interface Plan {
  id: number
  code: string
  name: string
  description: string | null
  price: string
  currency: 'USD' | 'KHR'
  /** Months one payment covers; null = paid once, no end. */
  period_months: number | null
  /** Active students allowed; null = no limit. */
  max_students: number | null
  modules: string[]
  status: 'active' | 'inactive'
  is_legacy: boolean
  live_count: number
  [key: string]: unknown
}

export type PlanInput = Pick<Plan, 'code' | 'name' | 'description' | 'currency' | 'period_months' | 'max_students' | 'modules'> & {
  price: string | number
  status?: Plan['status']
}

export type SubscriptionStatus = 'trial' | 'active' | 'expired' | 'cancelled' | 'replaced'
export type BillingFlag = 'none' | 'expiring' | 'expired' | 'cancelled' | 'no_plan'

export interface Subscription {
  id: number
  status: SubscriptionStatus
  live: boolean
  plan: Pick<Plan, 'id' | 'code' | 'name' | 'price' | 'currency' | 'period_months' | 'max_students' | 'modules'> | null
  start_on: string | null
  end_on: string | null
  days_left: number | null
  notes: string | null
  created_by: string | null
  ended_at: string | null
  created_at: string | null
}

export interface BillingSummary {
  flag: BillingFlag
  subscription: Subscription | null
}

export interface SchoolBilling extends BillingSummary {
  history: Subscription[]
  /** Active students against the plan's limit; null when the school's database could not be read. */
  usage: { active: number; limit: number | null } | null
}

export interface BillingSettingsRow {
  trial_days: number
  grace_days: number
  warning_days: number
  trial_plan_id: number | null
  company_name: string | null
  company_address: string | null
  company_phone: string | null
  company_email: string | null
  company_tax_number: string | null
  company_logo: string | null
  /** The logo as a data: URI, for the preview. */
  company_logo_data: string | null
}

export type PaymentMethod = 'cash' | 'bank' | 'khqr' | 'cheque' | 'other'

export interface SchoolPaymentRow {
  id: number
  receipt_number: string
  school: { id: number; name: string; slug: string } | null
  plan: string | null
  amount: string
  currency: 'USD' | 'KHR'
  periods: number
  method: PaymentMethod
  reference: string | null
  paid_on: string
  covered_from: string | null
  covered_to: string | null
  notes: string | null
  recorded_by: string | null
  void: boolean
  voided_at: string | null
  voided_by: string | null
  void_reason: string | null
  created_at: string | null
  [key: string]: unknown
}

export interface PaymentQuote {
  plan: string | null
  status: SubscriptionStatus
  currency: 'USD' | 'KHR'
  amount: string
  covered_from: string | null
  covered_to: string | null
}

export interface NewPayment {
  periods: number
  amount: string | null
  method: PaymentMethod
  reference: string | null
  paid_on: string | null
  notes: string | null
}

export const paymentsApi = {
  list: (query: ListQuery) => page<SchoolPaymentRow>('/billing/payments', query),
  quote: (schoolId: number, periods: number) => get<PaymentQuote>(`/schools/${schoolId}/billing/payment-quote`, { periods }),
  record: (schoolId: number, body: NewPayment) => send<SchoolPaymentRow>('post', `/schools/${schoolId}/billing/payments`, body),
  void: (id: number, reason: string) => send<SchoolPaymentRow>('post', `/billing/payments/${id}/void`, { reason }),
  receiptUrl: (id: number) => `/api/v1/platform/billing/payments/${id}/receipt`,
  uploadLogo: (file: File) => {
    const body = new FormData()
    body.append('logo', file)
    return send<BillingSettingsRow>('post', '/billing/settings/logo', body)
  },
  removeLogo: () => send<BillingSettingsRow>('delete', '/billing/settings/logo'),
}

export const billingApi = {
  plans: () => get<{ plans: Plan[]; modules: PlanModule[] }>('/billing/plans'),
  createPlan: (body: PlanInput) => send<Plan>('post', '/billing/plans', body),
  updatePlan: (id: number, body: Partial<PlanInput>) => send<Plan>('patch', `/billing/plans/${id}`, body),
  removePlan: (id: number) => send<{ deleted: boolean }>('delete', `/billing/plans/${id}`),
  settings: () => get<BillingSettingsRow>('/billing/settings'),
  saveSettings: (body: Partial<Omit<BillingSettingsRow, 'company_logo' | 'company_logo_data'>>) => send<BillingSettingsRow>('put', '/billing/settings', body),
  school: (id: number) => get<SchoolBilling>(`/schools/${id}/billing`),
  subscribe: (
    id: number,
    body: { plan_id: number; status: 'trial' | 'active'; start_on?: string | null; end_on?: string | null; notes?: string | null },
  ) => send<SchoolBilling>('post', `/schools/${id}/billing/subscribe`, body),
  changePlan: (id: number, body: { plan_id: number; notes?: string | null }) => send<SchoolBilling>('post', `/schools/${id}/billing/change-plan`, body),
  updateSubscription: (id: number, body: { end_on?: string | null; notes?: string | null }) => send<SchoolBilling>('patch', `/schools/${id}/billing`, body),
  cancel: (id: number, reason: string) => send<SchoolBilling>('post', `/schools/${id}/billing/cancel`, { reason }),
}

// ── Data ops (Stage 3)───────────────────────────────────────────────────────

export type DatabaseFlag = 'missing' | 'unreachable' | 'behind' | 'no_recent_backup'

export interface PlatformDatabase {
  database: string
  kind: 'central' | 'template' | 'shared' | 'school'
  placement: PlacementMode | null
  server: string | null
  schools: { id: number; name: string; slug: string; status: SchoolStatus }[]
  school: { id: number; name: string; slug: string; status: SchoolStatus } | null
  exists: boolean
  size_bytes: number | null
  pending_migrations: number | null
  error: string | null
  last_backup_at: string | null
  flags: DatabaseFlag[]
  [key: string]: unknown
}

export interface BackupRow {
  id: number
  database: string
  tenant_id: number | null
  trigger: 'manual' | 'scheduled' | 'move'
  format: 'dump' | 'school'
  school: { id: number; name: string; slug: string } | null
  staff: string | null
  status: 'running' | 'ok' | 'failed'
  size_bytes: number | null
  duration_ms: number | null
  error: string | null
  copied: boolean
  copy_error: string | null
  file: string | null
  created_at: string | null
  finished_at: string | null
  [key: string]: unknown
}

export interface BackupScheduleRow {
  enabled: boolean
  days: string[]
  at: string
  keep_days: number
  timezone: string
  last_run_at: string | null
  next_run_at: string | null
  copy_disk: string | null
}

export interface MigrationRunRow {
  id: number
  batch: string
  kind: 'migrate' | 'create'
  status: 'queued' | 'running' | 'ok' | 'failed'
  school: { id: number; name: string; slug: string } | null
  staff: string | null
  applied: number | null
  error: string | null
  duration_ms: number | null
  created_at: string | null
  [key: string]: unknown
}

export interface PlatformHealth {
  queue: { waiting: number | null; failed: number; latest_failures: { id: string; queue: string; message: string; failed_at: string }[] }
  scheduler: { last_tick_at: string | null; healthy: boolean }
  disk: { free_bytes: number | null; backups_bytes: number; copy_disk: string | null }
}

export const dataApi = {
  databases: () => get<PlatformDatabase[]>('/data/databases'),
  health: () => get<PlatformHealth>('/data/health'),
  backups: (query: ListQuery) => page<BackupRow>('/data/backups', query),
  backupNow: (database: string) => send<BackupRow[]>('post', '/data/backups', { database }),
  backupSchool: (schoolId: number) => send<BackupRow[]>('post', '/data/backups', { school_id: schoolId }),
  downloadUrl: (id: number) => `/api/v1/platform/data/backups/${id}/download`,
  removeBackup: (id: number) => send<void>('delete', `/data/backups/${id}`),
  schedule: () => get<BackupScheduleRow>('/data/backup-schedule'),
  saveSchedule: (body: Pick<BackupScheduleRow, 'enabled' | 'days' | 'at' | 'keep_days'>) => send<BackupScheduleRow>('put', '/data/backup-schedule', body),
  migrationRuns: (query: ListQuery) => page<MigrationRunRow>('/data/migration-runs', query),
  migrate: (tenantId: number | 'all') => send<{ batch: string | null; count: number }>('post', '/data/migrate', { tenant_id: tenantId }),
  createDatabase: (tenantId: number) => send<{ batch: string; count: number }>('post', `/data/schools/${tenantId}/create-database`),
}

/** 1536 → "1.5 KB". */
export function formatBytes(bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined) return '—'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let value = bytes
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }
  return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`
}

// ── Support views (stage 6b) ────────────────────────────────────────────────

export type SupportViewState = 'open' | 'ended' | 'timed_out'

export interface SupportView {
  id: number
  state: SupportViewState
  school: { id: number; name: string; slug: string } | null
  staff: { id: number; name: string } | null
  user: { id: number; name: string; email: string | null } | null
  reason: string
  started_at: string
  expires_at: string
  ended_at: string | null
  ended_by: 'staff' | 'timeout' | 'sign_out' | null
  ended_by_staff: string | null
  /** The console exchanged the ticket. */
  entered: boolean
  ip: string | null
  events_count: number
  /** Only on the detail call. */
  events: { method: string; path: string; status: number | null; at: string }[] | null
}

export interface SupportUser {
  id: number
  name: string
  email: string | null
  phone: string | null
  is_owner: boolean
}

export const supportApi = {
  list: (query: ListQuery) => page<SupportView>('/support/sessions', query),
  show: (id: number) => get<SupportView>(`/support/sessions/${id}`),
  users: (schoolId: number, search?: string) => get<SupportUser[]>(`/schools/${schoolId}/support/users`, search ? { search } : undefined),
  /** Returns the console address with the one-time ticket; it works once, within 60 seconds. */
  open: (schoolId: number, body: { user_id: number; reason: string }) => send<SupportView & { url: string }>('post', `/schools/${schoolId}/support/sessions`, body),
  end: (id: number) => send<SupportView>('post', `/support/sessions/${id}/end`),
  settings: () => get<{ duration_minutes: number }>('/support/settings'),
  updateSettings: (body: { duration_minutes: number }) => send<{ duration_minutes: number }>('patch', '/support/settings', body),
}

// ── Settings → Security ─────────────────────────────────────────────────────

export interface SecuritySettings {
  /** Staff sign in with a code from an authenticator app as well as their password. */
  require_two_factor: boolean
}

export const securityApi = {
  get: () => get<SecuritySettings>('/settings/security'),
  update: (body: SecuritySettings) => send<SecuritySettings>('put', '/settings/security', body),
}

// ── Website (stage 7) — NewTech's public website ────────────────────────────

export type WebsiteStatus = 'active' | 'inactive'
export type WebsiteImageKind = 'slides' | 'gallery' | 'promotions'

/** A home slide, gallery photo or promotion: a picture and a few of these fields. */
export interface WebsiteImage {
  id: number
  image_url: string
  title?: string | null
  subtitle?: string | null
  link_url?: string | null
  caption?: string | null
  sort_order: number
  status: WebsiteStatus
  created_at: string
}

export interface WebsiteSchool {
  id: number
  name: string
  name_en: string | null
  slug: string
  hostname: string
}

export interface WebsiteFolder {
  id: number
  parent_id: number | null
  name: string
  sort_order: number
  status: WebsiteStatus
  files_count?: number
  /** Top folders only. */
  children?: WebsiteFolder[]
  created_at: string
}

export interface WebsiteFile {
  id: number
  name: string
  extension: string
  mime_type: string | null
  size: number
  is_image: boolean
  url: string
  sort_order: number
  created_at: string
}

export interface WebsiteAbout {
  history_title: string
  history_paragraph_1: string
  history_paragraph_2: string
  history_image_url: string | null
  stats: { value: string; label: string }[]
  pillars: { icon: string; title: string; description: string }[]
  achievements_title: string
  achievements: { icon: string; value: string; label: string }[]
}

/** Matches StoreDownloadFileRequest (and upload_max_filesize). */
export const MAX_WEBSITE_FILE_BYTES = 50 * 1024 * 1024
export const WEBSITE_FILE_ACCEPT = '.jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.psd,.ai,.zip,.rar,.7z,.mp3,.mp4'

/** Text fields travel as multipart form data (pictures cannot be JSON); nested arrays as `a[0][b]`. */
function formData(fields: Record<string, unknown>): FormData {
  const form = new FormData()
  const add = (key: string, value: unknown): void => {
    if (value === undefined || value === null) return
    if (value instanceof File) form.append(key, value)
    else if (Array.isArray(value)) value.forEach((v, i) => add(`${key}[${i}]`, v))
    else if (typeof value === 'object') Object.entries(value as Record<string, unknown>).forEach(([k, v]) => add(`${key}[${k}]`, v))
    else form.append(key, String(value))
  }
  Object.entries(fields).forEach(([k, v]) => add(k, v))
  return form
}

export const websiteApi = {
  school: () => get<WebsiteSchool>('/website'),

  images: (kind: WebsiteImageKind, query: ListQuery) => page<WebsiteImage>(`/website/${kind}`, query),
  createImage: (kind: WebsiteImageKind, fields: Record<string, unknown>) => send<WebsiteImage>('post', `/website/${kind}`, formData(fields)),
  /** A POST with `_method=PUT`: PHP only reads uploaded files from a POST. */
  updateImage: (kind: WebsiteImageKind, id: number, fields: Record<string, unknown>) =>
    send<WebsiteImage>('post', `/website/${kind}/${id}`, formData({ ...fields, _method: 'PUT' })),
  removeImage: (kind: WebsiteImageKind, id: number) => send<null>('delete', `/website/${kind}/${id}`),

  folders: () => get<WebsiteFolder[]>('/website/download-folders'),
  createFolder: (body: { parent_id?: number | null; name: string; sort_order: number; status: WebsiteStatus }) =>
    send<WebsiteFolder>('post', '/website/download-folders', body),
  updateFolder: (id: number, body: { name: string; sort_order: number; status: WebsiteStatus }) =>
    send<WebsiteFolder>('patch', `/website/download-folders/${id}`, body),
  removeFolder: (id: number) => send<null>('delete', `/website/download-folders/${id}`),
  files: (folderId: number) => get<WebsiteFile[]>(`/website/download-folders/${folderId}/files`),
  async uploadFile(folderId: number, file: File, onProgress?: (fraction: number) => void): Promise<WebsiteFile> {
    const body = await call<WebsiteFile>({
      method: 'post',
      url: `/website/download-folders/${folderId}/files`,
      data: formData({ file }),
      onUploadProgress: onProgress ? (e) => e.total && onProgress(Math.min(1, e.loaded / e.total)) : undefined,
    })
    return body.data
  },
  renameFile: (id: number, name: string) => send<WebsiteFile>('patch', `/website/download-files/${id}`, { name }),
  removeFile: (id: number) => send<null>('delete', `/website/download-files/${id}`),

  about: () => get<WebsiteAbout>('/website/about'),
  saveAbout: (fields: Omit<WebsiteAbout, 'history_image_url'> & { history_image?: File }) => send<WebsiteAbout>('post', '/website/about', formData(fields)),
}

/** What a service card opens: its own page (/services/{slug}), the Computer School's pages, a web address, or the Downloads page. */
export type ServiceCardKind = 'page' | 'school' | 'link' | 'downloads'

/** One card on the website's "group of services" front page. Text fields come in _km and _en. */
export interface ServiceCard {
  id: string
  kind: ServiceCardKind
  /** Its own page's address, /services/{slug} — kind `page` only. */
  slug: string
  /** Where it goes — kind `link` only. */
  link_url: string
  status: WebsiteStatus
  sort_order: number
  title_km: string
  title_en: string
  text_km: string
  text_en: string
  image_url: string | null
  // Its own page (kind `page`):
  heading_km: string
  heading_en: string
  description_km: string
  description_en: string
  /** One feature per line. */
  features_km: string
  features_en: string
  button_label_km: string
  button_label_en: string
  button_url: string
}

export interface WebsiteServices {
  enabled: boolean
  cards: ServiceCard[]
}

export type ServiceCardInput = Omit<ServiceCard, 'id' | 'image_url'> & { image?: File }

export const websiteServicesApi = {
  get: () => get<WebsiteServices>('/website/services'),
  /** The switch alone; it applies at once. */
  setEnabled: (enabled: boolean) => send<WebsiteServices>('put', '/website/services/settings', { enabled }),
  addCard: (card: ServiceCardInput) => send<ServiceCard>('post', '/website/services/cards', formData({ ...card })),
  /** A POST with `_method=PUT`: PHP only reads uploaded files from a POST. */
  updateCard: (id: string, card: ServiceCardInput) => send<ServiceCard>('post', `/website/services/cards/${id}`, formData({ ...card, _method: 'PUT' })),
  removeCard: (id: string) => send<null>('delete', `/website/services/cards/${id}`),
}
