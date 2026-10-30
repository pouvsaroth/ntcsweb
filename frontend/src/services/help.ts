import { apiGet } from '@/services/http'

/**
 * Help & Support (docs/backoffice/stage-13-help-support.md): NewTech's
 * contacts, help articles and FAQ, in English and Khmer — see pickText().
 */
export interface HelpContact {
  phone: string | null
  telegram: string | null
  email: string | null
  hours_en: string | null
  hours_km: string | null
}

export interface HelpModule {
  code: string
  label: string
  label_km: string | null
}

export interface HelpArticleSummary {
  id: number
  module: string | null
  title_en: string | null
  title_km: string | null
  summary_en: string | null
  summary_km: string | null
  updated_at: string | null
}

export interface HelpArticle {
  id: number
  module: string | null
  module_label: string | null
  module_label_km: string | null
  title_en: string | null
  title_km: string | null
  body_en: string | null
  body_km: string | null
  updated_at: string | null
}

export interface HelpFaq {
  id: number
  module: string | null
  question_en: string | null
  question_km: string | null
  answer_en: string | null
  answer_km: string | null
}

export interface HelpCenter {
  contact: HelpContact
  modules: HelpModule[]
  articles: HelpArticleSummary[]
  faqs: HelpFaq[]
}

export const helpService = {
  get: () => apiGet<HelpCenter>('/help'),
  article: (id: number) => apiGet<HelpArticle>(`/help/articles/${id}`),
}
