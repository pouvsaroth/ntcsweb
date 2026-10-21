import { apiDelete, apiGet, apiPost, apiPut } from '@/services/http'

/**
 * Academic > Timetable (docs/academics/timetable.md). The timetable is the
 * classes' own weekly times (also set on the class form); each time can name
 * its subject, teacher and room.
 */

export interface TimetableSlot {
  id: number
  class_id: number
  class: string | null
  academic_year: string | null
  day_of_week: number
  start_time: string
  end_time: string
  book_id: number | null
  subject: string | null
  staff_id: number | null
  teachers: { id: number; name: string }[]
  teacher_from_class: boolean
  classroom_id: number | null
  room_id: number | null
  room: string | null
  room_from_class: boolean
  note: string | null
}

export interface TimetableConflict {
  type: 'class' | 'teacher' | 'room' | 'unavailable'
  day_of_week: number
  who: string | null
  staff_id: number | null
  slots: { id: number; class_id: number; class: string | null; start_time: string; end_time: string; subject: string | null; room: string | null }[]
  reason: string | null
}

export interface TimetableOptions {
  classes: { id: number; name: string; academic_year: string | null; room: string | null }[]
  teachers: { id: number; name: string }[]
  rooms: { id: number; name: string }[]
  subjects: { id: number; title: string }[]
  me: number | null
}

export interface TeacherWeek {
  week_start: string
  slots: TimetableSlot[]
  substitutions: { id: number; date: string; day_of_week: number; start_time: string | null; end_time: string | null; class: string | null; role: 'covering' | 'away'; other: string | null }[]
  hours_per_week: number
}

export interface SubjectHours {
  class: string
  has_grade: boolean
  subjects: { book_id: number | null; subject: string | null; timetable_hours: number; curriculum_hours: number | null; assigned_hours: number | null }[]
}

export interface SlotInput {
  class_id: number
  day_of_week: number
  start_time: string
  end_time: string
  book_id: number | null
  staff_id: number | null
  classroom_id: number | null
  note: string | null
}

export interface ScheduleChange {
  id: number
  class_schedule_id: number
  class: string
  from: { day_of_week: number; start_time: string; end_time: string }
  to: { day_of_week: number; start_time: string; end_time: string }
  new_room: string | null
  new_teacher: string | null
  reason: string
  status: 'pending' | 'approved' | 'rejected' | 'cancelled'
  requested_by: string | null
  mine: boolean
  created_at: string
  decided_by: string | null
  decision_note: string | null
}

export const timetableService = {
  options: () => apiGet<TimetableOptions>('/timetable/options'),
  forClass: (classId: number) => apiGet<{ slots: TimetableSlot[]; conflicts: TimetableConflict[] }>('/timetable/class', { params: { class_id: classId } }),
  forTeacher: (staffId: number, week?: string) => apiGet<TeacherWeek>('/timetable/teacher', { params: { staff_id: staffId, week } }),
  forRoom: (roomId: number) => apiGet<{ slots: TimetableSlot[] }>('/timetable/room', { params: { classroom_id: roomId } }),
  subjects: (classId: number) => apiGet<SubjectHours>('/timetable/subjects', { params: { class_id: classId } }),
  conflicts: () => apiGet<TimetableConflict[]>('/timetable/conflicts'),
  createSlot: (input: SlotInput) => apiPost<{ slot: TimetableSlot; conflicts: TimetableConflict[] }>('/timetable/slots', input),
  updateSlot: (id: number, input: SlotInput) => apiPut<{ slot: TimetableSlot; conflicts: TimetableConflict[] }>(`/timetable/slots/${id}`, input),
  removeSlot: (id: number) => apiDelete(`/timetable/slots/${id}`),

  changes: (status?: string) => apiGet<ScheduleChange[]>('/timetable/changes', { params: { status: status || undefined } }),
  requestChange: (input: { class_schedule_id: number; new_day_of_week: number; new_start_time: string; new_end_time: string; new_classroom_id: number | null; new_staff_id: number | null; reason: string }) =>
    apiPost<{ id: number }>('/timetable/changes', input),
  approve: (id: number, note?: string) => apiPost<{ status: string; conflicts: TimetableConflict[] }>(`/timetable/changes/${id}/approve`, { note }),
  reject: (id: number, note?: string) => apiPost(`/timetable/changes/${id}/reject`, { note }),
  cancel: (id: number) => apiPost(`/timetable/changes/${id}/cancel`),
}
