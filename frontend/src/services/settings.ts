import { apiGetWithMeta, apiPost } from '@/services/http'

export interface GeneralSettings {
  student_id_prefix: string
  staff_id_prefix: string
}

export const generalSettingsService = {
  get: () => apiGetWithMeta<GeneralSettings>('/settings/general').then((r) => r.data),

  /** The backend still generates every Student/Staff ID (see StudentIdGenerator/StaffIdGenerator) — this only ever changes the prefix used going forward. */
  update: (settings: { studentIdPrefix: string; staffIdPrefix: string }) =>
    apiPost<GeneralSettings>('/settings/general', {
      student_id_prefix: settings.studentIdPrefix,
      staff_id_prefix: settings.staffIdPrefix,
    }),
}
