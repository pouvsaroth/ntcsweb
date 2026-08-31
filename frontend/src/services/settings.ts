import { apiGetWithMeta, apiPost } from '@/services/http'

export interface GeneralSettings {
  student_id_prefix: string
}

export const generalSettingsService = {
  get: () => apiGetWithMeta<GeneralSettings>('/settings/general').then((r) => r.data),

  /** The backend still generates every Student ID (see StudentIdGenerator) — this only ever changes the prefix used going forward. */
  update: (studentIdPrefix: string) => apiPost<GeneralSettings>('/settings/general', { student_id_prefix: studentIdPrefix }),
}
