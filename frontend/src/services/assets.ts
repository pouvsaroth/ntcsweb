import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type AssetStatus =
  | 'IN_STOCK'
  | 'ASSIGNED'
  | 'IN_USE'
  | 'ISSUE_REPORTED'
  | 'UNDER_INSPECTION'
  | 'BROKEN'
  | 'UNDER_REPAIR'
  | 'REPAIR_COMPLETED'
  | 'READY_FOR_USE'
  | 'STOPPED_USE'
  | 'RETIRED'
  | 'DISPOSED'
  | 'LOST'
  | 'MISSING'

export const assetStatuses: AssetStatus[] = [
  'IN_STOCK', 'ASSIGNED', 'IN_USE', 'ISSUE_REPORTED', 'UNDER_INSPECTION', 'BROKEN', 'UNDER_REPAIR',
  'REPAIR_COMPLETED', 'READY_FOR_USE', 'STOPPED_USE', 'RETIRED', 'DISPOSED', 'LOST', 'MISSING',
]

export type AssetCondition = 'NEW' | 'EXCELLENT' | 'GOOD' | 'FAIR' | 'DAMAGED' | 'BROKEN' | 'UNUSABLE'

export const assetConditions: AssetCondition[] = ['NEW', 'EXCELLENT', 'GOOD', 'FAIR', 'DAMAGED', 'BROKEN', 'UNUSABLE']

export type DisposalMethod = 'RECYCLED' | 'SOLD' | 'DONATED' | 'DESTROYED' | 'RETURNED_TO_VENDOR' | 'OTHER'

export const disposalMethods: DisposalMethod[] = ['RECYCLED', 'SOLD', 'DONATED', 'DESTROYED', 'RETURNED_TO_VENDOR', 'OTHER']

/** The short keys AssignAssetRequest accepts — the backend resolves each to its whitelisted model class (see AssignableType). */
export type AssignableType = 'staff' | 'student' | 'user' | 'department' | 'classroom'

export const assignableTypes: AssignableType[] = ['staff', 'student', 'user', 'department', 'classroom']

export interface AssetAssignment {
  id: number
  asset_id: number
  assignable_type: string
  assignable_id: number
  assignable_label: string | null
  assigned_by: number | null
  assigned_date: string | null
  expected_return_date: string | null
  returned_date: string | null
  condition_at_assignment: AssetCondition | null
  condition_at_return: AssetCondition | null
  status: 'ACTIVE' | 'RETURNED'
  notes: string | null
  created_at: string
}

export interface AssetTransfer {
  id: number
  asset_id: number
  from_location: string | null
  to_location: string | null
  from_department: string | null
  to_department: string | null
  transferred_by: string | null
  transfer_date: string | null
  reason: string | null
  notes: string | null
  created_at: string
}

export interface AssetHistoryEntry {
  id: number
  asset_id: number
  event_type: string
  description: string
  old_value: Record<string, unknown> | null
  new_value: Record<string, unknown> | null
  occurred_at: string
  actor: string | null
}

export interface AssetDocument {
  id: number
  asset_id: number
  type: string
  file_name: string
  mime_type: string | null
  caption: string | null
  url: string
  uploaded_by: string | null
  created_at: string
}

export interface Asset {
  id: number
  asset_number: string
  name: string
  description: string | null
  brand: string | null
  model: string | null
  serial_number: string | null
  asset_tag: string | null
  category_id: number
  category: { id: number; code: string; name: string } | null
  purchase_date: string | null
  purchase_price: number
  current_value: number | null
  supplier_id: number | null
  supplier: { id: number; name: string } | null
  warranty_start_date: string | null
  warranty_end_date: string | null
  warranty_provider: string | null
  warranty_number: string | null
  warranty_is_active: boolean
  location_id: number | null
  location: { id: number; code: string; name: string } | null
  department_id: number | null
  department: { id: number; name: string } | null
  status: AssetStatus
  condition: AssetCondition
  hostname: string | null
  mac_address: string | null
  ip_address: string | null
  specs: Record<string, unknown> | null
  disposal_date: string | null
  disposal_reason: string | null
  disposal_method: DisposalMethod | null
  disposal_value: number | null
  current_assignment: AssetAssignment | null
  notes: string | null
  created_at: string
  updated_at: string
}

export interface AssetInput {
  category_id: number | null
  name: string
  description: string
  brand: string
  model: string
  serial_number: string
  asset_tag: string
  purchase_date: string
  purchase_price: string
  current_value: string
  supplier_id: number | null
  warranty_start_date: string
  warranty_end_date: string
  warranty_provider: string
  warranty_number: string
  location_id: number | null
  department_id: number | null
  hostname: string
  mac_address: string
  ip_address: string
  specs: Record<string, unknown>
  notes: string
}

function toAssetPayload(input: AssetInput) {
  return {
    category_id: input.category_id,
    name: input.name,
    description: input.description || null,
    brand: input.brand || null,
    model: input.model || null,
    serial_number: input.serial_number || null,
    asset_tag: input.asset_tag || null,
    purchase_date: input.purchase_date || null,
    purchase_price: input.purchase_price ? Number(input.purchase_price) : null,
    current_value: input.current_value ? Number(input.current_value) : null,
    supplier_id: input.supplier_id,
    warranty_start_date: input.warranty_start_date || null,
    warranty_end_date: input.warranty_end_date || null,
    warranty_provider: input.warranty_provider || null,
    warranty_number: input.warranty_number || null,
    location_id: input.location_id,
    department_id: input.department_id,
    hostname: input.hostname || null,
    mac_address: input.mac_address || null,
    ip_address: input.ip_address || null,
    specs: input.specs,
    notes: input.notes || null,
  }
}

export const assetsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Asset>> {
    const result = await apiGetWithMeta<Asset[]>('/assets', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Identity-scoped — assets currently assigned to the signed-in account, no `assets.view` permission needed. See MyAssetController. */
  async myAssets(query: PaginatedQuery): Promise<PaginatedResult<Asset>> {
    const result = await apiGetWithMeta<Asset[]>('/my-assets', { params: { page: query.page, per_page: query.per_page, sort: query.sort } })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<Asset>(`/assets/${id}`).then((r) => r.data),

  create: (input: AssetInput) => apiPost<Asset>('/assets', toAssetPayload(input)),
  update: (id: number, input: AssetInput) => apiPut<Asset>(`/assets/${id}`, toAssetPayload(input)),
  remove: (id: number) => apiDelete(`/assets/${id}`),

  assign: (
    id: number,
    payload: { assignable_type: AssignableType; assignable_id: number; assigned_date?: string; expected_return_date?: string; condition_at_assignment?: AssetCondition; notes?: string },
  ) => apiPost<AssetAssignment>(`/assets/${id}/assign`, payload),

  returnAsset: (id: number, payload: { condition_at_return?: AssetCondition; notes?: string }) =>
    apiPost<Asset>(`/assets/${id}/return`, payload),

  transfer: (id: number, payload: { to_location_id?: number | null; to_department_id?: number | null; reason?: string; notes?: string }) =>
    apiPost<AssetTransfer>(`/assets/${id}/transfer`, payload),

  changeCondition: (id: number, condition: AssetCondition, notes?: string) =>
    apiPost<Asset>(`/assets/${id}/change-condition`, { condition, notes }),

  retire: (id: number, reason: string) => apiPost<Asset>(`/assets/${id}/retire`, { reason }),

  dispose: (id: number, payload: { method: DisposalMethod; reason: string; value?: number; notes?: string }) =>
    apiPost<Asset>(`/assets/${id}/dispose`, payload),

  markLost: (id: number, payload: { last_known_location?: string; description?: string }) =>
    apiPost<Asset>(`/assets/${id}/mark-lost`, payload),

  markFound: (id: number, notes?: string) => apiPost<Asset>(`/assets/${id}/mark-found`, { notes }),

  async history(id: number, query: PaginatedQuery): Promise<PaginatedResult<AssetHistoryEntry>> {
    const result = await apiGetWithMeta<AssetHistoryEntry[]>(`/assets/${id}/history`, { params: { page: query.page, per_page: query.per_page } })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  async assignments(id: number, query: PaginatedQuery): Promise<PaginatedResult<AssetAssignment>> {
    const result = await apiGetWithMeta<AssetAssignment[]>(`/assets/${id}/assignments`, { params: { page: query.page, per_page: query.per_page } })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  async transfers(id: number, query: PaginatedQuery): Promise<PaginatedResult<AssetTransfer>> {
    const result = await apiGetWithMeta<AssetTransfer[]>(`/assets/${id}/transfers`, { params: { page: query.page, per_page: query.per_page } })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  documents: (id: number) => apiGetWithMeta<AssetDocument[]>(`/assets/${id}/documents`).then((r) => r.data),

  uploadDocument: (id: number, file: File, type?: string, caption?: string) => {
    const form = new FormData()
    form.append('file', file)
    if (type) form.append('type', type)
    if (caption) form.append('caption', caption)
    return apiPost<AssetDocument>(`/assets/${id}/documents`, form, { headers: { 'Content-Type': 'multipart/form-data' } })
  },

  removeDocument: (id: number, documentId: number) => apiDelete(`/assets/${id}/documents/${documentId}`),
}
