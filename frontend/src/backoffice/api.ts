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
  login: (email: string, password: string) => send<{ two_factor: 'required' | 'setup' }>('post', '/auth/login', { email, password }),
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
  [key: string]: unknown
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

// ── Data ops (Stage 3) ───────────────────────────────────────────────────────

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
