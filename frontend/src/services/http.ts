import axios, { type AxiosInstance, type AxiosRequestConfig, isAxiosError } from 'axios'

import type { ApiError, ApiSuccess } from '@/types/api'
import { ApiRequestError } from '@/types/api'

/**
 * The one Axios instance for the whole app.
 *
 * `withCredentials: true` is what makes Sanctum's session-cookie auth work —
 * every request carries the session/XSRF cookies. `baseURL: '/api/v1'` is a
 * relative path on purpose: in dev, Vite's proxy (vite.config.ts) forwards it
 * to the backend so the browser sees one origin; in production, nginx does
 * the same. The SPA never needs to know the backend's real address.
 */
export const http: AxiosInstance = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: {
    Accept: 'application/json',
  },
})

export interface ActingTenant {
  id: number
  slug: string
  name: string
}

const ACTING_TENANT_STORAGE_KEY = 'ntcsweb.acting_tenant'

/**
 * Production-safe, unlike setDevTenant below: lets a signed-in platform
 * Super Admin browse a specific school's admin data. EnsureTenantMatchesUser
 * already exempts super admins server-side ("acting inside an arbitrary
 * school is their job") — this is the client half, pointing every
 * subsequent request at the chosen school via X-Tenant (the same header
 * RequestTenantResolver reads on the central domain) and persisting the
 * choice across page loads within this browser tab.
 */
export function setActingTenant(tenant: ActingTenant | null): void {
  if (tenant === null) {
    sessionStorage.removeItem(ACTING_TENANT_STORAGE_KEY)
    delete http.defaults.headers.common['X-Tenant']
    return
  }

  sessionStorage.setItem(ACTING_TENANT_STORAGE_KEY, JSON.stringify(tenant))
  http.defaults.headers.common['X-Tenant'] = tenant.slug
}

export function getActingTenant(): ActingTenant | null {
  const raw = sessionStorage.getItem(ACTING_TENANT_STORAGE_KEY)
  if (!raw) return null

  try {
    return JSON.parse(raw) as ActingTenant
  } catch {
    return null
  }
}

// Restored before anything else below (dev's own auto-detect included) so a
// deliberate choice always wins over a guess, in both dev and production.
const restoredActingTenant = getActingTenant()
if (restoredActingTenant) http.defaults.headers.common['X-Tenant'] = restoredActingTenant.slug

const DEV_TENANT_STORAGE_KEY = 'ntcsweb.dev_tenant'

/**
 * Sentinel stored in place of a slug when a signed-in session has explicitly
 * decided there is no tenant (a platform Super Admin) — distinct from the key
 * being merely absent, which means "not decided yet, auto-detect below".
 * Without this, the boot logic below would re-auto-pick a tenant on the very
 * next page refresh and silently drag a super admin's session back into one
 * school's scope.
 */
const DEV_TENANT_NONE = '__none__'

/**
 * Dev-only: points every subsequent request at a specific tenant (or
 * explicitly none), overriding whatever the boot-time auto-detect below
 * guessed. Called once a real session exists (see stores/auth.ts) — the
 * login form's own school choice, or lack of one, is authoritative over a
 * guess made before anyone had signed in. No-ops in production, where the
 * hostname is always what resolves the tenant.
 */
export function setDevTenant(tenant: string | number | null): void {
  if (!import.meta.env.DEV) return

  if (tenant === null) {
    sessionStorage.setItem(DEV_TENANT_STORAGE_KEY, DEV_TENANT_NONE)
    delete http.defaults.headers.common['X-Tenant']
    return
  }

  const value = String(tenant)
  sessionStorage.setItem(DEV_TENANT_STORAGE_KEY, value)
  http.defaults.headers.common['X-Tenant'] = value
}

/**
 * Dev-only: forgets a session's tenant decision entirely (as opposed to
 * `setDevTenant(null)`, which *records* "deliberately none"). Called on
 * logout so the next visitor — browsing the public site before signing in,
 * or landing on a different school's login page via `?tenant=` — gets a
 * fresh auto-detect rather than inheriting whoever was signed in before.
 */
export function resetDevTenant(): void {
  if (!import.meta.env.DEV) return
  sessionStorage.removeItem(DEV_TENANT_STORAGE_KEY)
}

/**
 * Dev-only convenience: on a real deployment, the tenant resolves from the
 * hostname (a school's subdomain or custom domain) — nothing here is needed.
 * Bare `localhost`, though, is a *central* domain (see TENANCY_CENTRAL_DOMAINS
 * and RequestTenantResolver), so it can't resolve a tenant from the host at
 * all; without this, every public endpoint 404s in local dev, silently,
 * because nothing else in the app ever attaches an X-Tenant header.
 * `import.meta.env.DEV` keeps all of this entirely out of a production build.
 *
 * `?tenant=<slug>` on the URL still wins when present (useful with more than
 * one local tenant), remembered in sessionStorage so client-side navigation
 * doesn't lose it. Otherwise, since a full manual step is an easy thing for
 * a non-technical tester to miss or get wrong, this falls back to asking the
 * same unauthenticated `/tenants` directory the login page's own school
 * dropdown uses — if there's exactly one tenant it's picked automatically;
 * with several (this project's local DB carries a handful of factory-seeded
 * test tenants alongside the real one), tenant id 1 wins if it's among
 * them — that's the real one actually being worked on here — otherwise no
 * guess is made and `?tenant=<slug>` is required. A session that already
 * decided it has no tenant (see DEV_TENANT_NONE above) skips all of this —
 * and so does one already restored above via setActingTenant, a deliberate
 * choice a guess here must never overwrite.
 *
 * A top-level `await` here means every other module that (transitively)
 * imports this one waits for it to finish before running — so this is
 * guaranteed to have already set the header before the app's first API call,
 * not racing it.
 */
if (import.meta.env.DEV && !restoredActingTenant) {
  const fromUrl = new URLSearchParams(window.location.search).get('tenant')

  if (fromUrl) sessionStorage.setItem(DEV_TENANT_STORAGE_KEY, fromUrl)

  let tenant: string | null = fromUrl ?? sessionStorage.getItem(DEV_TENANT_STORAGE_KEY)

  if (tenant === DEV_TENANT_NONE) {
    tenant = null
  } else if (!tenant) {
    try {
      const response = await http.get<ApiSuccess<{ id: number; slug: string }[]>>('/tenants', { params: { per_page: 100 } })
      const tenants = response.data.data
      const picked = tenants.length === 1 ? tenants[0] : tenants.find((t) => t.id === 1)

      if (picked) {
        tenant = picked.slug
        sessionStorage.setItem(DEV_TENANT_STORAGE_KEY, tenant)
        // eslint-disable-next-line no-console
        console.info(`[dev] Auto-selected tenant "${tenant}". Add ?tenant=<slug> to the URL to pick a different one.`)
      }
    } catch {
      // The tenant directory itself needs no tenant to answer, so a failure
      // here means something bigger is wrong (backend down, etc.) — every
      // other request is about to fail the same way regardless, so this
      // just leaves X-Tenant unset rather than blocking app startup on it.
    }
  }

  if (tenant) http.defaults.headers.common['X-Tenant'] = tenant
}

/**
 * Sanctum's stateful (cookie) auth requires this to be called once before the
 * first state-changing request in a session — it sets the XSRF-TOKEN cookie
 * that `http`'s xsrfCookieName/xsrfHeaderName config then attaches
 * automatically to every subsequent request. Safe to call repeatedly; the
 * auth store only calls it right before login.
 */
export async function primeCsrfCookie(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

/**
 * Unwraps { success, data } and throws ApiRequestError for both HTTP-level
 * failures and API-level { success: false } bodies, so every call site can
 * use one catch pattern instead of checking `.success` by hand everywhere.
 */
export async function apiGet<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  return unwrap(http.get<ApiSuccess<T>>(url, config))
}

export async function apiPost<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
  return unwrap(http.post<ApiSuccess<T>>(url, body, config))
}

export async function apiPut<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
  return unwrap(http.put<ApiSuccess<T>>(url, body, config))
}

export async function apiPatch<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
  return unwrap(http.patch<ApiSuccess<T>>(url, body, config))
}

export async function apiDelete<T = void>(url: string, config?: AxiosRequestConfig): Promise<T> {
  return unwrap(http.delete<ApiSuccess<T>>(url, config))
}

/**
 * Full response access for endpoints where `meta` (e.g. pagination) matters
 * to the caller, not just `data`.
 */
export async function apiGetWithMeta<T>(
  url: string,
  config?: AxiosRequestConfig,
): Promise<ApiSuccess<T>> {
  try {
    const response = await http.get<ApiSuccess<T>>(url, config)
    return response.data
  } catch (error) {
    throw toApiRequestError(error)
  }
}

/**
 * Same as apiGetWithMeta, but for a create call — used where `meta` carries
 * a one-time value the response body's `data` shape doesn't otherwise have
 * room for, e.g. `meta.temporary_password` on Student/Staff/User creation
 * (see UserProvisioningService on the backend).
 */
export async function apiPostWithMeta<T>(
  url: string,
  body?: unknown,
  config?: AxiosRequestConfig,
): Promise<ApiSuccess<T>> {
  try {
    const response = await http.post<ApiSuccess<T>>(url, body, config)
    return response.data
  } catch (error) {
    throw toApiRequestError(error)
  }
}

async function unwrap<T>(request: Promise<{ data: ApiSuccess<T> }>): Promise<T> {
  try {
    const response = await request
    return response.data.data
  } catch (error) {
    throw toApiRequestError(error)
  }
}

function toApiRequestError(error: unknown): ApiRequestError {
  if (isAxiosError<ApiError>(error)) {
    const status = error.response?.status ?? 0
    const body = error.response?.data

    if (body && typeof body === 'object' && 'success' in body) {
      return new ApiRequestError(body.message, status, body.error?.code, body.errors)
    }

    return new ApiRequestError(error.message, status)
  }

  return new ApiRequestError('An unexpected error occurred.', 0)
}

/**
 * For an endpoint that returns a raw file (a PDF invoice/receipt) rather than
 * an { success, data } envelope — GETs it as a blob, then hands the browser a
 * throwaway <a download> to save it, the same way a plain navigation to the
 * URL would, except this one carries the session cookie via `withCredentials`
 * and survives the axios instance's baseURL. An error response still arrives
 * as a Blob (the server doesn't know the client wanted JSON), so it's read
 * back out as text and parsed the same way a normal failure would be.
 */
export async function apiDownload(url: string, filename: string): Promise<void> {
  try {
    const response = await http.get<Blob>(url, { responseType: 'blob' })
    const blobUrl = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = blobUrl
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(blobUrl)
  } catch (error) {
    if (isAxiosError(error) && error.response?.data instanceof Blob) {
      const status = error.response.status
      try {
        const body = JSON.parse(await error.response.data.text()) as ApiError
        throw new ApiRequestError(body.message, status, body.error?.code, body.errors)
      } catch (parseError) {
        if (parseError instanceof ApiRequestError) throw parseError
        throw new ApiRequestError('The file could not be downloaded.', status)
      }
    }

    throw toApiRequestError(error)
  }
}
