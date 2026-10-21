import { apiDelete, apiDownload, apiGet, apiPost, apiPut } from '@/services/http'

/** Academic > Lesson plans (docs/academics/lesson-plans.md). */

export type PlanStatus = 'draft' | 'submitted' | 'approved' | 'returned'
export type LogStatus = 'done' | 'partial' | 'not_done'

export interface LessonOptions {
  me: number | null
  sees_all: boolean
  classes: { id: number; name: string; grade_level_id: number | null; grade_level: string | null; academic_year: string | null }[]
  subjects: { id: number; title: string }[]
  teachers: { id: number; name: string | null }[]
}

export interface PlanRow {
  id: number
  staff_id: number
  teacher: string | null
  class_id: number
  class: string | null
  book_id: number | null
  subject: string | null
  curriculum_lesson_id: number | null
  curriculum_lesson: string | null
  planned_date: string
  title: string
  status: PlanStatus
  files_count: number
  taught: boolean
  days_late?: number
}

export interface PlanDetail extends PlanRow {
  objectives: string | null
  materials: string | null
  activities: string | null
  homework: string | null
  homework_due: string | null
  review_note: string | null
  reviewed_by: string | null
  reviewed_at: string | null
  files: { id: number; name: string; size: number; mime: string | null }[]
  logs: { id: number; taught_on: string; status: LogStatus; note: string | null }[]
  can_edit: boolean
}

export interface PlanInput {
  staff_id: number | null
  class_id: number
  book_id: number | null
  curriculum_lesson_id: number | null
  planned_date: string
  title: string
  objectives: string | null
  materials: string | null
  activities: string | null
  homework: string | null
  homework_due: string | null
}

export interface TeachingLogRow {
  id: number
  staff_id: number
  teacher: string | null
  class_id: number
  class: string | null
  book_id: number | null
  subject: string | null
  curriculum_lesson_id: number | null
  curriculum_lesson: string | null
  lesson_plan_id: number | null
  taught_on: string
  title: string
  status: LogStatus
  homework: string | null
  note: string | null
}

export interface LogInput {
  staff_id: number | null
  class_id: number
  book_id: number | null
  curriculum_lesson_id: number | null
  lesson_plan_id: number | null
  taught_on: string
  title: string
  status: LogStatus
  homework: string | null
  note: string | null
}

export interface Progress {
  class: string
  has_grade: boolean
  subjects: {
    book_id: number
    subject: string | null
    total: number
    done: number
    partial: number
    remaining: number
    percent: number
    next: string | null
    lessons: { id: number; title: string; status: 'done' | 'partial' | null }[]
  }[]
}

const clean = <T extends object>(o: T) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== null && v !== undefined && v !== ''))

export const lessonPlansService = {
  options: () => apiGet<LessonOptions>('/lesson-plans/options'),
  curriculumLessons: (classId: number, bookId: number) =>
    apiGet<{ id: number; title: string; hours: number | null }[]>('/lesson-plans/curriculum-lessons', { params: { class_id: classId, book_id: bookId } }),

  plans: (filter: { from?: string; to?: string; staff_id?: number; class_id?: number; status?: string }) => apiGet<PlanRow[]>('/lesson-plans/plans', { params: clean(filter) }),
  get: (id: number) => apiGet<PlanDetail>(`/lesson-plans/plans/${id}`),
  create: (input: PlanInput) => apiPost<{ id: number }>('/lesson-plans/plans', input),
  update: (id: number, input: PlanInput) => apiPut<{ id: number; status: PlanStatus }>(`/lesson-plans/plans/${id}`, input),
  remove: (id: number) => apiDelete(`/lesson-plans/plans/${id}`),
  submit: (id: number) => apiPost<{ status: PlanStatus }>(`/lesson-plans/plans/${id}/submit`),
  review: (id: number, decision: 'approve' | 'return', note: string | null) => apiPost<{ status: PlanStatus }>(`/lesson-plans/plans/${id}/review`, { decision, note }),
  upload(id: number, file: File) {
    const form = new FormData()
    form.append('file', file)
    return apiPost<{ id: number; name: string; size: number; mime: string | null }>(`/lesson-plans/plans/${id}/files`, form)
  },
  download: (file: { id: number; name: string }) => apiDownload(`/lesson-plans/files/${file.id}`, file.name),
  removeFile: (fileId: number) => apiDelete(`/lesson-plans/files/${fileId}`),

  logs: (filter: { from?: string; to?: string; staff_id?: number; class_id?: number }) => apiGet<TeachingLogRow[]>('/lesson-plans/logs', { params: clean(filter) }),
  createLog: (input: LogInput) => apiPost<TeachingLogRow>('/lesson-plans/logs', input),
  updateLog: (id: number, input: LogInput) => apiPut<TeachingLogRow>(`/lesson-plans/logs/${id}`, input),
  removeLog: (id: number) => apiDelete(`/lesson-plans/logs/${id}`),

  progress: (classId: number) => apiGet<Progress>('/lesson-plans/progress', { params: { class_id: classId } }),
  delays: (filter: { staff_id?: number; class_id?: number }) => apiGet<PlanRow[]>('/lesson-plans/delays', { params: clean(filter) }),
}

/** yyyy-mm-dd of a local date. */
export function isoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
