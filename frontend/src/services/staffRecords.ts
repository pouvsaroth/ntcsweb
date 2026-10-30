import { apiDelete, apiGet, apiPost, apiPut } from '@/services/http'

/**
 * The list-style tabs of the staff form (docs/hrm/staff-profile-tabs.md):
 * rows per staff member, one endpoint shape for every kind.
 */
export type StaffRecordValue = string | number | null
export type StaffRecord = { id: number } & Record<string, StaffRecordValue>

export const staffRecordsService = {
  list: (staffId: number, kind: string) => apiGet<StaffRecord[]>(`/staff/${staffId}/records/${kind}`),
  create: (staffId: number, kind: string, input: Record<string, StaffRecordValue>) => apiPost<StaffRecord>(`/staff/${staffId}/records/${kind}`, input),
  update: (staffId: number, kind: string, id: number, input: Record<string, StaffRecordValue>) => apiPut<StaffRecord>(`/staff/${staffId}/records/${kind}/${id}`, input),
  remove: (staffId: number, kind: string, id: number) => apiDelete(`/staff/${staffId}/records/${kind}/${id}`),
}
