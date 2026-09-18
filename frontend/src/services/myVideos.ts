import { apiGet } from '@/services/http'

export interface MyVideo {
  id: number
  title: string
  description: string | null
  thumbnail_url: string | null
  embed_url: string | null
}

export interface MyVideoCourse {
  id: number
  name: string
  thumbnail_url: string | null
  video_count: number
  videos: MyVideo[]
}

/** Student self-service — the courses they're actively studying, each with its own video list. Nothing here is locked (see MyVideoController). */
export const myVideosService = {
  list: () => apiGet<MyVideoCourse[]>('/my-videos'),
}
