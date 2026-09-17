import { apiGetWithMeta, apiPost, apiPostWithMeta, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'
import type { User } from '@/types/models'

export interface CreateUserInput {
  name: string
  phone: string
  email: string
  /** Links an existing, not-yet-linked Student instead of a standalone account — its role is always forced to Student. */
  student_id?: number
  /** Required when student_id is absent. */
  role_id?: number
}

export interface UserCreated {
  user: User
  /** Shown once, right after creation — see UserProvisioningService on the backend. */
  temporaryPassword: string | null
}

export interface UpdateUserInput {
  name: string
  email: string
  /** Omit entirely for a student-linked account — its role is always Student and cannot be reassigned here. */
  role_id?: number
}

export const adminUsersService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<User>> {
    const result = await apiGetWithMeta<User[]>('/users', {
      params: {
        page: query.page,
        per_page: query.per_page,
        search: query.search,
        sort: query.sort,
        filter: query.filter,
      },
    })

    const pagination = result.meta?.pagination as LengthAwarePaginationMeta

    return { data: result.data, pagination }
  },

  async create(input: CreateUserInput): Promise<UserCreated> {
    const result = await apiPostWithMeta<User>('/users', input)
    return { user: result.data, temporaryPassword: (result.meta?.temporary_password as string) ?? null }
  },

  update: (userId: number, input: UpdateUserInput) => apiPut<User>(`/users/${userId}`, input),

  /** A School Admin setting a new password for a student's or staff member's login — never usable on the admin's own account (see UserPolicy::resetPassword()). */
  resetPassword: (userId: number, password: string, passwordConfirmation: string) =>
    apiPost<void>(`/users/${userId}/reset-password`, { password, password_confirmation: passwordConfirmation }),

  /** Clears every live session/token for a user stuck out of the one-device login rule — see UserController::forceLogout(). */
  forceLogout: (userId: number) => apiPost<void>(`/users/${userId}/force-logout`),
}
