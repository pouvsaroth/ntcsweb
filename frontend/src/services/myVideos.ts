import { apiGet } from '@/services/http'

export interface MyVideo {
  id: number
  title: string
  description: string | null
  thumbnail_url: string | null
  /** Course paid by month: no embed_url. */
  is_locked: boolean
  embed_url: string | null
}

export interface MyVideoCourse {
  id: number
  name: string
  thumbnail_url: string | null
  video_count: number
  /** 'monthly': the student pays this course by month, so every video is locked. */
  locked: 'monthly' | null
  videos: MyVideo[]
}

/** Student self-service — the courses they're actively studying, each with its own video list. A course paid by month comes locked (see MyVideoController). */
export const myVideosService = {
  list: () => apiGet<MyVideoCourse[]>('/my-videos'),
}
