import { apiGet } from '@/services/http'

/** Parent portal: one of the signed-in parent's children. */
export interface MyChild {
  id: number
  name: string
  english_name: string | null
  student_code: string
  photo_url: string | null
  status: string
  /** Father, Mother, Guardian... */
  relation: string | null
  /** The classes the child studies in now. */
  classes: string[]
}

export const parentPortalService = {
  children: () => apiGet<MyChild[]>('/my-children'),
}
