import { apiDelete, apiGet, apiPost, apiPut } from '@/services/http'

/**
 * Academics > School setup (docs/academics/school-setup.md): terms, grade
 * levels, curriculum, school calendar, promotion rules and promoting students.
 */

export interface AcademicTerm {
  id: number
  academic_year_id: number
  academic_year: string | null
  name: string
  start_date: string | null
  end_date: string | null
  sort_order: number
}
export type AcademicTermInput = Omit<AcademicTerm, 'id' | 'academic_year'>

export const GRADE_STAGES = ['kindergarten', 'primary', 'lower_secondary', 'upper_secondary', 'other'] as const
export type GradeStage = (typeof GRADE_STAGES)[number]

export interface GradeLevel {
  id: number
  code: string
  name: string
  stage: GradeStage | null
  level_order: number
  is_active: boolean
  classes_count: number
}
export type GradeLevelInput = Omit<GradeLevel, 'id' | 'classes_count'>

export interface CurriculumLesson {
  id: number
  grade_level_id: number
  book_id: number
  title: string
  hours: number | null
  description: string | null
  sort_order: number
}
export interface CurriculumSubject {
  book_id: number
  title: string
  status: string
  hours_per_week: number | null
  lessons_hours: number
  lessons: CurriculumLesson[]
}
export interface Curriculum {
  grade_level: { id: number; code: string; name: string }
  subjects: CurriculumSubject[]
  hours_per_week: number
  available_subjects: { id: number; title: string }[]
}

export const CALENDAR_TYPES = ['opening', 'closing', 'exam', 'event', 'meeting', 'other'] as const
export type CalendarType = (typeof CALENDAR_TYPES)[number]
export interface CalendarEvent {
  id: number
  title: string
  type: CalendarType
  start_date: string
  end_date: string
  academic_year_id: number | null
  academic_year: string | null
  description: string | null
}
export interface CalendarHoliday {
  id: number
  title: string
  holiday_type: string | null
  start_date: string
  end_date: string
  description: string | null
}
export interface CalendarTerm {
  id: number
  name: string
  academic_year: string | null
  start_date: string
  end_date: string
}
export interface CalendarData {
  events: CalendarEvent[]
  holidays: CalendarHoliday[]
  terms: CalendarTerm[]
}
export interface CalendarEventInput {
  title: string
  type: CalendarType
  start_date: string
  end_date: string | null
  academic_year_id: number | null
  description: string | null
}

export interface PromotionRule {
  id: number
  grade_level_id: number | null
  grade_level: string | null
  min_average: number | null
  min_attendance: number | null
  max_failed_subjects: number | null
  pass_mark: number
  notes: string | null
}
export type PromotionRuleInput = Omit<PromotionRule, 'id' | 'grade_level'>

export type PromotionReason = 'no_scores' | 'average_low' | 'no_attendance' | 'attendance_low' | 'too_many_failed'
export interface PromotionStudent {
  enrollment_id: number
  student_id: number
  student_code: string | null
  student_name: string | null
  enrollment_status: string
  average: number | null
  subjects: number
  failed_subjects: number | null
  attendance_rate: number | null
  passes: boolean
  reasons: PromotionReason[]
  done: { result: 'promoted' | 'repeated' | 'graduated'; to_class: string | null; at: string | null } | null
}
export interface PromotionClassOption {
  id: number
  name: string
  academic_year: string | null
  grade_level: string | null
}
export interface PromotionView {
  class: { id: number; name: string; academic_year: string | null; grade_level: { id: number; name: string } | null }
  next_grade_level: { id: number; name: string } | null
  rule: PromotionRule | null
  students: PromotionStudent[]
  promote_classes: PromotionClassOption[]
  repeat_classes: PromotionClassOption[]
}
export type PromotionAction = 'promote' | 'repeat' | 'graduate'

export const academicTermsService = {
  list: (academicYearId?: number | null) => apiGet<AcademicTerm[]>('/academic-terms', { params: { academic_year_id: academicYearId || undefined } }),
  create: (input: AcademicTermInput) => apiPost<AcademicTerm>('/academic-terms', input),
  update: (id: number, input: AcademicTermInput) => apiPut<AcademicTerm>(`/academic-terms/${id}`, input),
  remove: (id: number) => apiDelete(`/academic-terms/${id}`),
}

export const gradeLevelsService = {
  list: (activeOnly = false) => apiGet<GradeLevel[]>('/grade-levels', { params: { active_only: activeOnly ? 1 : undefined } }),
  create: (input: GradeLevelInput) => apiPost<GradeLevel>('/grade-levels', input),
  update: (id: number, input: GradeLevelInput) => apiPut<GradeLevel>(`/grade-levels/${id}`, input),
  remove: (id: number) => apiDelete(`/grade-levels/${id}`),
  seedDefaults: () => apiPost<{ added: number }>('/grade-levels/defaults'),
}

export const curriculumService = {
  show: (gradeLevelId: number) => apiGet<Curriculum>('/curriculum', { params: { grade_level_id: gradeLevelId } }),
  saveSubject: (gradeLevelId: number, bookId: number, hoursPerWeek: number | null) =>
    apiPut('/curriculum/subjects', { grade_level_id: gradeLevelId, book_id: bookId, hours_per_week: hoursPerWeek }),
  removeSubject: (gradeLevelId: number, bookId: number) => apiDelete('/curriculum/subjects', { data: { grade_level_id: gradeLevelId, book_id: bookId } }),
  createLesson: (input: { grade_level_id: number; book_id: number; title: string; hours: number | null; description: string | null }) =>
    apiPost<CurriculumLesson>('/curriculum/lessons', input),
  updateLesson: (id: number, input: { title: string; hours: number | null; description: string | null; sort_order?: number }) =>
    apiPut<CurriculumLesson>(`/curriculum/lessons/${id}`, input),
  removeLesson: (id: number) => apiDelete(`/curriculum/lessons/${id}`),
}

export const schoolCalendarService = {
  range: (from: string, to: string) => apiGet<CalendarData>('/school-calendar', { params: { from, to } }),
  create: (input: CalendarEventInput) => apiPost<CalendarEvent>('/school-calendar', input),
  update: (id: number, input: CalendarEventInput) => apiPut<CalendarEvent>(`/school-calendar/${id}`, input),
  remove: (id: number) => apiDelete(`/school-calendar/${id}`),
}

export const promotionsService = {
  rules: () => apiGet<PromotionRule[]>('/promotion-rules'),
  saveRule: (input: PromotionRuleInput) => apiPut<PromotionRule>('/promotion-rules', input),
  removeRule: (id: number) => apiDelete(`/promotion-rules/${id}`),
  classes: (gradeLevelId: number) =>
    apiGet<{ id: number; name: string; status: string; academic_year: string | null; students_count: number }[]>('/student-promotions/classes', {
      params: { grade_level_id: gradeLevelId },
    }),
  show: (classId: number) => apiGet<PromotionView>('/student-promotions', { params: { class_id: classId } }),
  apply: (input: { class_id: number; to_class_id: number | null; repeat_class_id: number | null; students: { enrollment_id: number; action: PromotionAction }[] }) =>
    apiPost<{ promoted: number; repeated: number; graduated: number; skipped: number }>('/student-promotions', input),
}
