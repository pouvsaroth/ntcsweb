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
