import { apiGet, apiPost } from '@/services/http'
import type { AboutContent } from '@/stores/site'

export type { AboutContent, AboutStat, AboutPillar, AboutAchievement } from '@/stores/site'

export interface AboutPageInput {
  /** Omitted when the admin isn't replacing the history photo. */
  history_image?: File
  history_title: string
  history_paragraph_1: string
  history_paragraph_2: string
  stats: { value: string; label: string }[]
  pillars: { icon: string; title: string; description: string }[]
  achievements_title: string
  achievements: { icon: string; value: string; label: string }[]
}

/**
 * A fixed-shape singleton (4 stats, 3 pillars, 4 achievements), not a list —
 * see backend App\Support\Content\AboutPageContent. Nested arrays travel as
 * bracket-notation FormData keys (`stats[0][value]`, ...), which PHP parses
 * back into the same nested array shape automatically.
 */
function toFormData(input: AboutPageInput): FormData {
  const form = new FormData()

  if (input.history_image) form.append('history_image', input.history_image)
  form.append('history_title', input.history_title)
  form.append('history_paragraph_1', input.history_paragraph_1)
  form.append('history_paragraph_2', input.history_paragraph_2)
  form.append('achievements_title', input.achievements_title)

  input.stats.forEach((stat, index) => {
    form.append(`stats[${index}][value]`, stat.value)
    form.append(`stats[${index}][label]`, stat.label)
  })

  input.pillars.forEach((pillar, index) => {
    form.append(`pillars[${index}][icon]`, pillar.icon)
    form.append(`pillars[${index}][title]`, pillar.title)
    form.append(`pillars[${index}][description]`, pillar.description)
  })

  input.achievements.forEach((achievement, index) => {
    form.append(`achievements[${index}][icon]`, achievement.icon)
    form.append(`achievements[${index}][value]`, achievement.value)
    form.append(`achievements[${index}][label]`, achievement.label)
  })

  return form
}

export const aboutPageService = {
  get: () => apiGet<AboutContent>('/settings/about'),
  save: (input: AboutPageInput) => apiPost<AboutContent>('/settings/about', toFormData(input)),
}
