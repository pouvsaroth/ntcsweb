import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type ProjectStatus = 'active' | 'archived'
export type ProjectTaskPriority = 'low' | 'medium' | 'high'

export interface ProjectTask {
  id: number
  project_column_id: number
  title: string
  description: string | null
  priority: ProjectTaskPriority
  due_date: string | null
  order: number
  assignee_id: number | null
  assignee: string | null
  created_by: string | null
  created_at: string
}

export interface ProjectColumn {
  id: number
  project_id: number
  name: string
  color: string | null
  order: number
  tasks: ProjectTask[]
}

export interface Project {
  id: number
  name: string
  description: string | null
  status: ProjectStatus
  created_by: string | null
  task_count?: number
  columns: ProjectColumn[]
  created_at: string
}

export interface ProjectInput {
  name: string
  description?: string | null
  status?: ProjectStatus
}

export interface ProjectColumnInput {
  name: string
  color?: string | null
}

export interface ProjectTaskInput {
  title: string
  description?: string | null
  priority?: ProjectTaskPriority
  due_date?: string | null
  assignee_id?: number | null
}

export const projectsService = {
  async list(query: Partial<PaginatedQuery> = {}): Promise<PaginatedResult<Project>> {
    const result = await apiGetWithMeta<Project[]>('/projects', {
      params: { page: query.page, per_page: query.per_page ?? 50, sort: query.sort ?? '-created_at', filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<Project>(`/projects/${id}`).then((r) => r.data),
  create: (input: ProjectInput) => apiPost<Project>('/projects', input),
  update: (id: number, input: Partial<ProjectInput>) => apiPut<Project>(`/projects/${id}`, input),
  remove: (id: number) => apiDelete(`/projects/${id}`),
}

export const projectColumnsService = {
  create: (projectId: number, input: ProjectColumnInput) => apiPost<ProjectColumn>(`/projects/${projectId}/columns`, input),
  update: (id: number, input: ProjectColumnInput) => apiPut<ProjectColumn>(`/project-columns/${id}`, input),
  remove: (id: number) => apiDelete(`/project-columns/${id}`),
  reorder: (projectId: number, columnIds: number[]) =>
    apiPost<ProjectColumn[]>(`/projects/${projectId}/columns/reorder`, { column_ids: columnIds }),
}

export const projectTasksService = {
  create: (columnId: number, input: ProjectTaskInput) => apiPost<ProjectTask>(`/project-columns/${columnId}/tasks`, input),
  update: (id: number, input: Partial<ProjectTaskInput>) => apiPut<ProjectTask>(`/project-tasks/${id}`, input),
  remove: (id: number) => apiDelete(`/project-tasks/${id}`),
  move: (id: number, projectColumnId: number, order: number) =>
    apiPost<ProjectTask>(`/project-tasks/${id}/move`, { project_column_id: projectColumnId, order }),
}
