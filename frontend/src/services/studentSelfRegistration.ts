import { apiPost } from '@/services/http'

/**
 * The public registration wizard's submission contract — see backend
 * App\Http\Requests\Api\V1\Public\StoreStudentRegistrationRequest and
 * StudentRegistrationService. Nothing here creates an active account: the
 * result is always a pending registration awaiting a school admin's
 * approval (see admin/StudentRegistrationsPending.vue).
 */
export interface StudentSelfRegistrationInput {
  first_name: string
  last_name: string
  gender: string
  date_of_birth: string
  phone: string
  email: string
  house_no: string
  street_no: string
  village_code: string
  other_address: string
  photo?: File
  class_id: number
  course_package_id: number
  fee_type: string
  payment_method: 'CASH' | 'QR'
  password: string
  password_confirmation: string
}

function toFormData(input: StudentSelfRegistrationInput): FormData {
  const form = new FormData()

  form.append('first_name', input.first_name)
  form.append('last_name', input.last_name)
  if (input.gender) form.append('gender', input.gender)
  if (input.date_of_birth) form.append('date_of_birth', input.date_of_birth)
  form.append('phone', input.phone)
  if (input.email.trim()) form.append('email', input.email.trim())
  if (input.house_no.trim()) form.append('house_no', input.house_no.trim())
  if (input.street_no.trim()) form.append('street_no', input.street_no.trim())
  if (input.village_code) form.append('village_code', input.village_code)
  if (input.other_address.trim()) form.append('other_address', input.other_address.trim())
  if (input.photo) form.append('photo', input.photo)
  form.append('class_id', String(input.class_id))
  form.append('course_package_id', String(input.course_package_id))
  form.append('fee_type', input.fee_type)
  form.append('payment_method', input.payment_method)
  form.append('password', input.password)
  form.append('password_confirmation', input.password_confirmation)

  return form
}

export const studentSelfRegistrationService = {
  submit: (input: StudentSelfRegistrationInput) =>
    apiPost<{ student_code: string }>('/public/student-registrations', toFormData(input)),
}
