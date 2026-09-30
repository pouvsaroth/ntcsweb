import { apiGet } from '@/services/http'

/** The school's mention scale — see the backend's ExamMention. */
export type ExamMention = 'excellent' | 'very_good' | 'good' | 'fail'

export interface MyScore {
  exam_application_id: number
  book: string | null
  course_package: string | null
  exam_date: string | null
  score: number
  mention: ExamMention
  is_make_up: boolean
}

/** Student self-service — every score entered for the signed-in student, newest exam first. See MyExamScoreController. */
export const myScoresService = {
  list: () => apiGet<MyScore[]>('/my-scores'),
}
