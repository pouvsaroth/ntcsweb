import { apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type StudentImportStatus = 'pending' | 'processing' | 'completed' | 'failed'

export interface StudentImportRowError {
  row: number
  message: string
}

export interface StudentImport {
  id: number
  original_filename: string
  status: StudentImportStatus
  total_rows: number
  imported_count: number
  skipped_count: number
  errors: StudentImportRowError[] | null
  started_at: string | null
  completed_at: string | null
  created_at: string
}

export const studentImportsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<StudentImport>> {
    const result = await apiGetWithMeta<StudentImport[]>('/student-imports', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  upload(file: File) {
    const form = new FormData()
    form.append('file', file)
    return apiPost<StudentImport>('/student-imports', form)
  },
}
