import { apiGet, apiGetWithMeta, apiPost } from '@/services/http'
import { ApiRequestError, type LengthAwarePaginationMeta, type PaginatedResult } from '@/types/api'

/**
 * These endpoints (news, events, teachers) don't exist on the backend yet —
 * they land with the Website Management phase. Every read here is built
 * against that intended contract and fails closed to an empty result rather
 * than an error screen, so the public site looks intentionally "no content
 * yet" today and needs zero frontend changes once the real endpoints ship.
 * `programs`, `gallery`, `home-slides`, and `schedules` are the exceptions:
 * they're real, each backed by its own Public\*Controller.
 */

export interface NewsItem {
  id: number
  slug: string
  title: string
  excerpt: string
  cover_image: string | null
  published_at: string
}

export interface EventItem {
  id: number
  slug: string
  title: string
  location: string | null
  starts_at: string
  ends_at: string | null
  cover_image: string | null
}

export interface GalleryImage {
  id: number
  url: string
  caption: string | null
}

export interface HomeSlide {
  id: number
  image_url: string
  title: string | null
  subtitle: string | null
  link_url: string | null
  sort_order: number
}

export interface Program {
  id: number
  title: string
  subtitle: string | null
  category: string
  level: 'beginner' | 'intermediate' | 'advanced'
  duration_label: string | null
  fee: number | null
  description: string | null
  image_url: string | null
}

/** A real, enrollable course from the admin's academic catalog — see Public\CoursePackageController. */
export interface PublicCourse {
  id: number
  name: string
  description: string | null
  thumbnail_url: string | null
  fee_monthly: number | null
  fee_term: number | null
  fee_video: number | null
  fee_monthly_online: number | null
  fee_term_online: number | null
  currency: 'USD' | 'KHR'
  duration: string | null
  academic_program: { id: number; name: string; sort_order: number } | null
}

export interface Teacher {
  id: number
  name: string
  title: string | null
  photo: string | null
}

export interface ClassScheduleSlot {
  /** ISO-8601: 1 = Monday ... 7 = Sunday. */
  day_of_week: number
  start_time: string
  end_time: string
}

export interface ScheduledClass {
  id: number
  name: string
  teacher_name: string | null
  schedules: ClassScheduleSlot[]
}

/**
 * A video lesson on the public Video Lesson page. `embed_url` is only ever
 * present when `is_locked` is false — a locked video's playable URL is
 * withheld server-side, not just hidden by the UI (see
 * Public\VideoLessonController).
 */
export interface PublicVideo {
  id: number
  title: string
  description: string | null
  thumbnail_url: string | null
  is_locked: boolean
  embed_url: string | null
}

export interface PublicVideoCourse {
  id: number
  name: string
  videos: PublicVideo[]
}

function emptyPagination(total = 0): LengthAwarePaginationMeta {
  return {
    type: 'length_aware',
    current_page: 1,
    per_page: 0,
    total,
    last_page: 1,
    from: total > 0 ? 1 : null,
    to: total > 0 ? total : null,
  }
}

async function fetchPublicList<T>(url: string, params?: Record<string, unknown>): Promise<PaginatedResult<T>> {
  try {
    const result = await apiGetWithMeta<T[]>(url, { params })
    const pagination = result.meta?.pagination as LengthAwarePaginationMeta | undefined
    return { data: result.data, pagination: pagination ?? emptyPagination(result.data.length) }
  } catch (error) {
    if (error instanceof ApiRequestError && (error.status === 404 || error.status === 0)) {
      return { data: [], pagination: emptyPagination() }
    }
    throw error
  }
}

async function fetchPublicOne<T>(url: string): Promise<T | null> {
  try {
    return await apiGetWithMeta<T>(url).then((r) => r.data)
  } catch (error) {
    if (error instanceof ApiRequestError && (error.status === 404 || error.status === 0)) {
      return null
    }
    throw error
  }
}

// Memoized within a page load: several CourseCards can each expand their
// "Detail & Fee" panel and ask for this course's videos — they share one
// request against the full catalog instead of each re-fetching it.
let videoLessonsCache: Promise<PublicVideoCourse[]> | null = null

export const publicContentService = {
  // Unlike the other endpoints in this file, this one is real — the
  // fetchPublicList wrapper is used anyway for consistency and because it
  // still degrades gracefully (an empty slider, not a broken page) for a
  // tenant on an older API version that predates it.
  getHomeSlides: () => fetchPublicList<HomeSlide>('/public/home-slides', { per_page: 20 }),

  getNews: (page = 1, perPage = 9) => fetchPublicList<NewsItem>('/public/news', { page, per_page: perPage }),
  getNewsBySlug: (slug: string) => fetchPublicOne<NewsItem>(`/public/news/${slug}`),

  getEvents: (page = 1, perPage = 9) => fetchPublicList<EventItem>('/public/events', { page, per_page: perPage }),

  getGallery: (page = 1, perPage = 12) => fetchPublicList<GalleryImage>('/public/gallery', { page, per_page: perPage }),

  getPrograms: (options: { featured?: boolean } = {}) =>
    fetchPublicList<Program>('/public/programs', { per_page: 50, featured: options.featured ? 1 : undefined }),

  /**
   * Real, unlike most of this file — see Public\CoursePackageController.
   * `featured` switches from the full catalog (`show_on_website`) to the
   * homepage's "Popular Programs" set (`show_in_popular`) — the two flags
   * are independent, same as `getPrograms({ featured })`'s own convention.
   */
  getCourses: (options: { featured?: boolean } = {}) =>
    fetchPublicList<PublicCourse>('/public/course-packages', { per_page: 200, featured: options.featured ? 1 : undefined }),

  /** Which currently-running classes offer this package, with their weekly schedule — the registration wizard's "Schedule" step. */
  getCourseClasses: (coursePackageId: number) => apiGet<ScheduledClass[]>(`/public/course-packages/${coursePackageId}/classes`),

  /** A fixed-amount Bakong KHQR code for one specific invoice amount — see backend App\Support\Billing\Khqr. */
  getKhqrPreview: (amount: number, currency: 'USD' | 'KHR') =>
    apiGet<{ khqr_string: string }>('/public/khqr-preview', { params: { amount, currency } }),

  /** Real — see Public\VideoLessonController. Courses grouped with their video lessons, each flagged whether the current viewer can actually play it. */
  getVideoLessons: () => apiGet<PublicVideoCourse[]>('/public/video-lessons'),

  /** This course's video menu (title + lock state) for the "Detail & Fee" panel — `null` when the course has no videos published (`show_videos` off, or none active). */
  async getVideosForCourse(coursePackageId: number): Promise<PublicVideoCourse | null> {
    if (!videoLessonsCache) videoLessonsCache = apiGet<PublicVideoCourse[]>('/public/video-lessons')
    const courses = await videoLessonsCache
    return courses.find((course) => course.id === coursePackageId) ?? null
  },

  getTeachers: (page = 1, perPage = 12) => fetchPublicList<Teacher>('/public/teachers', { page, per_page: perPage }),

  getSchedules: () => fetchPublicList<ScheduledClass>('/public/schedules', { per_page: 100 }),

  /**
   * Submitting a message failing must be visible to the visitor, not silently
   * swallowed — unlike the read endpoints above, this does not catch 404.
   */
  submitContactMessage: (payload: { name: string; email: string; subject: string; message: string }) =>
    apiPost<void>('/public/contact-messages', payload),

  /** Real, unlike submitContactMessage above — see EnrollmentInquiryController. */
  submitEnrollmentInquiry: (payload: { name: string; phone: string; email: string; program_id: string; message: string }) =>
    apiPost<void>('/public/enrollment-inquiries', payload),
}
