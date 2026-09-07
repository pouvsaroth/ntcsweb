import { apiDelete, apiGetWithMeta, apiPost } from '@/services/http'

export interface ProjectTaskComment {
  id: number
  body: string
  user_id: number
  user_name: string | null
  created_at: string
}

export interface ProjectTaskHistoryEntry {
  id: number
  action: string
  description: string | null
  user: { id: number; name: string; email: string } | null
  created_at: string
}

export const projectTaskCommentsService = {
  list: (taskId: number) => apiGetWithMeta<ProjectTaskComment[]>(`/project-tasks/${taskId}/comments`).then((r) => r.data),
  create: (taskId: number, body: string) => apiPost<ProjectTaskComment>(`/project-tasks/${taskId}/comments`, { body }),
  remove: (commentId: number) => apiDelete(`/project-task-comments/${commentId}`),
}

export const projectTaskHistoryService = {
  list: (taskId: number) =>
    apiGetWithMeta<ProjectTaskHistoryEntry[]>(`/project-tasks/${taskId}/history`).then((r) => r.data),
}
