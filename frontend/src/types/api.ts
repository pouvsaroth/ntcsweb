/**
 * Mirrors App\Http\Responses\ApiResponse on the backend exactly. Every HTTP
 * client call in this app should type its response with one of these, never
 * an ad-hoc shape — see src/services/http.ts for where this is unwrapped.
 */
export interface ApiSuccess<T> {
  success: true
  message?: string
  data: T
  meta?: Record<string, unknown>
}

export interface ApiError {
  success: false
  message: string
  error?: { code: string }
  errors?: Record<string, string[]>
}

export type ApiResult<T> = ApiSuccess<T> | ApiError

/** meta.pagination for a length-aware paginated response (ApiQuery::paginate()). */
export interface LengthAwarePaginationMeta {
  type: 'length_aware'
  current_page: number
  per_page: number
  total: number
  last_page: number
  from: number | null
  to: number | null
}

/** meta.pagination for a cursor-paginated response (ApiQuery::cursorPaginate()). */
export interface CursorPaginationMeta {
  type: 'cursor'
  per_page: number
  next_cursor: string | null
  prev_cursor: string | null
  has_more: boolean
}

export type PaginationMeta = LengthAwarePaginationMeta | CursorPaginationMeta

export interface PaginatedResult<T> {
  data: T[]
  pagination: PaginationMeta
}

/**
 * A structured API failure, thrown by the http client so callers can
 * `catch (e) { if (e instanceof ApiRequestError) ... }` instead of parsing
 * axios errors by hand at every call site.
 */
export class ApiRequestError extends Error {
  readonly status: number
  readonly code?: string
  readonly errors?: Record<string, string[]>

  constructor(message: string, status: number, code?: string, errors?: Record<string, string[]>) {
    super(message)
    this.name = 'ApiRequestError'
    this.status = status
    this.code = code
    this.errors = errors
  }

  /** The field-level message for one input, if this was a 422. */
  fieldError(field: string): string | undefined {
    return this.errors?.[field]?.[0]
  }
}
