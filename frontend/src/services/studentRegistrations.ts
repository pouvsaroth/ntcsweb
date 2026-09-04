import { apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/**
 * The admin "Student Registration Pending" queue's row/detail shape — a
 * self-registered Student (see the public wizard, still to come) sitting at
 * Student::STATUS_PENDING with an unpaid Invoice, waiting for an admin to
 * confirm the cash payment and approve it. See
 * backend/app/Services/Academic/StudentRegistrationService.php.
 */
export interface StudentRegistration {
  id: number
  student_code: string
  first_name: string
  last_name: string
  full_name: string
  photo_url: string | null
  gender: string | null
  date_of_birth: string | null
  phone: string
  email: string | null
  house_no: string | null
  street_no: string | null
  village_code: string | null
  other_address: string | null
  status: string
  enrollment: {
    id: number
    class: { id: number; name: string } | null
    course_package: { id: number; name: string } | null
    academic_program: { id: number; name: string } | null
    fee: number
    fee_type: string
  } | null
  invoice: {
    id: number
    invoice_number: string
    total: number
    balance: number
    currency: string
    /** How the registrant intends to pay — 'CASH' or 'QR' — set at registration, used as the payment method Approve records. */
    intended_payment_method: string | null
  } | null
  created_at: string
}

export const studentRegistrationsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<StudentRegistration>> {
    const result = await apiGetWithMeta<StudentRegistration[]>('/student-registrations', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<StudentRegistration>(`/student-registrations/${id}`).then((r) => r.data),
  approve: (id: number) => apiPost<StudentRegistration>(`/student-registrations/${id}/approve`),
  reject: (id: number, reason: string) => apiPost<StudentRegistration>(`/student-registrations/${id}/reject`, { reason }),
}
