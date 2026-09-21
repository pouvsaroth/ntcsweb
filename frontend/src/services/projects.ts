import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type ProjectStatus = 'active' | 'archived'
export type ProjectTaskPriority = 'low' | 'medium' | 'high'

export interface ProjectLabel {
  id: number
  name: string
  color: string | null
}

export interface ProjectTaskChecklistItem {
  id: number
  title: string
  is_completed: boolean
  order: number
}

export interface ProjectTaskAttachment {
  id: number
  file_name: string
  mime_type: string | null
  url: string
  uploaded_by: string | null
  created_at: string
}

/** A compact reference to another card — what a dependency list is made of. */
export interface ProjectTaskSummary {
  id: number
  title: string
  project_column_id: number
  priority: ProjectTaskPriority
}

export interface ProjectTask {
  id: number
  project_column_id: number
  title: string
  description: string | null
  priority: ProjectTaskPriority
  start_date: string | null
  due_date: string | null
  estimated_hours: number | null
  order: number
  assignee_id: number | null
  assignee: string | null
  created_by: string | null
  project_milestone_id: number | null
  milestone: { id: number; name: string } | null
  labels: ProjectLabel[]
  checklist_items: ProjectTaskChecklistItem[]
  checklist_progress: { completed: number; total: number } | null
  attachments: ProjectTaskAttachment[]
  dependencies: ProjectTaskSummary[]
  created_at: string
}

export interface ProjectMilestone {
  id: number
  project_id: number
  name: string
  start_date: string | null
  due_date: string | null
  order: number
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
  milestones: ProjectMilestone[]
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

export interface ProjectMilestoneInput {
  name: string
  start_date?: string | null
  due_date?: string | null
}

export interface ProjectTaskInput {
  title: string
  description?: string | null
  priority?: ProjectTaskPriority
  start_date?: string | null
  due_date?: string | null
  estimated_hours?: number | null
  project_milestone_id?: number | null
  assignee_id?: number | null
  label_ids?: number[]
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

export const projectMilestonesService = {
  create: (projectId: number, input: ProjectMilestoneInput) => apiPost<ProjectMilestone>(`/projects/${projectId}/milestones`, input),
  update: (id: number, input: Partial<ProjectMilestoneInput>) => apiPut<ProjectMilestone>(`/project-milestones/${id}`, input),
  remove: (id: number) => apiDelete(`/project-milestones/${id}`),
}

export const projectLabelsService = {
  list: () => apiGetWithMeta<ProjectLabel[]>('/project-labels').then((r) => r.data),
  create: (name: string, color?: string | null) => apiPost<ProjectLabel>('/project-labels', { name, color }),
  remove: (id: number) => apiDelete(`/project-labels/${id}`),
}

export const projectTaskChecklistItemsService = {
  create: (taskId: number, title: string) =>
    apiPost<ProjectTaskChecklistItem>(`/project-tasks/${taskId}/checklist-items`, { title }),
  update: (id: number, input: Partial<Pick<ProjectTaskChecklistItem, 'title' | 'is_completed'>>) =>
    apiPut<ProjectTaskChecklistItem>(`/project-task-checklist-items/${id}`, input),
  remove: (id: number) => apiDelete(`/project-task-checklist-items/${id}`),
}

export const projectTaskAttachmentsService = {
  list: (taskId: number) => apiGetWithMeta<ProjectTaskAttachment[]>(`/project-tasks/${taskId}/attachments`).then((r) => r.data),
  upload: (taskId: number, file: File) => {
    const form = new FormData()
    form.append('file', file)
    return apiPost<ProjectTaskAttachment>(`/project-tasks/${taskId}/attachments`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },
  remove: (taskId: number, attachmentId: number) => apiDelete(`/project-tasks/${taskId}/attachments/${attachmentId}`),
}

export const projectTaskDependenciesService = {
  add: (taskId: number, dependsOnTaskId: number) =>
    apiPost<ProjectTaskSummary[]>(`/project-tasks/${taskId}/dependencies`, { depends_on_project_task_id: dependsOnTaskId }),
  remove: (taskId: number, dependsOnTaskId: number) => apiDelete(`/project-tasks/${taskId}/dependencies/${dependsOnTaskId}`),
}
