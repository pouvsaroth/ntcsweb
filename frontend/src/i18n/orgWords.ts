/**
 * Organization types (back office › Organization types): a Company, Ministry…
 * sees its own word wherever the admin screens say "school". The word for each
 * language comes from /auth/me (meta.tenant.organization); a School changes
 * nothing. Applied to a copy of the built-in text, before the school's own
 * words (Settings › Language › Translation), so those still win.
 */

export interface OrganizationWords {
  en: string
  en_plural?: string
  km?: string
  zh?: string
  ja?: string
  ko?: string
}

type MessageTree = { [key: string]: string | MessageTree }

/** Website pages and sign-in: these always talk about schools as such. */
const SKIP_SECTIONS = new Set([
  'home', 'about', 'programs', 'schedule', 'registerWizard', 'events', 'announcements', 'gallery',
  'downloads', 'contact', 'servicesPage', 'demo', 'plansPage', 'signupPage', 'siteOffline', 'auth', 'nav',
])

/** A student's earlier schools stay schools. */
const SKIP_KEYS = new Set([
  'admin.students.educationHint',
  'admin.students.addEducation',
  'admin.students.educationN',
  'admin.students.schoolName',
])

/** Phrases that mean a real school, whatever the customer is. */
const KEEP = /school year|school management system|computer school|high school|primary school|secondary school|pre-?school/i

/** Placeholders ({school}) and linked keys (@:common.school) are never touched. */
const CODE = /(\{[^}]*\}|@(?:\.\w+)?:[\w.]+)/

function matchCase(source: string, word: string): string {
  if (source === source.toUpperCase()) return word.toUpperCase()
  // An acronym (NGO) keeps its capitals.
  if (/[A-Z]/.test(word.slice(1))) return word
  if (source[0] === source[0].toUpperCase()) return word[0].toUpperCase() + word.slice(1)
  return word.toLowerCase()
}

/** One piece of text, e.g. a role name from the database ("School Admin" → "Company Admin"). */
export function swapOrganizationText(text: string, locale: string, w: OrganizationWords): string {
  if (KEEP.test(text)) return text
  return text
    .split(CODE)
    .map((part, i) => {
      if (i % 2 === 1) return part
      switch (locale) {
        case 'en': {
          const plural = w.en_plural || `${w.en}s`
          return part
            .replace(/\bschools\b/gi, (m) => matchCase(m, plural))
            .replace(/\bschool\b/gi, (m) => matchCase(m, w.en))
        }
        case 'km':
          return w.km ? part.replace(/សាលារៀន|សាលា/g, w.km) : part
        case 'zh':
          return w.zh ? part.replace(/学校/g, w.zh) : part
        case 'ja':
          return w.ja ? part.replace(/学校/g, w.ja) : part
        case 'ko':
          return w.ko ? part.replace(/학교/g, w.ko) : part
        default:
          return part
      }
    })
    .join('')
}

/** Swaps the word through one language's messages, in place. */
export function swapOrganizationWords(messages: MessageTree, locale: string, words: OrganizationWords): void {
  const walk = (node: MessageTree, path: string): void => {
    for (const [key, value] of Object.entries(node)) {
      const full = path ? `${path}.${key}` : key
      if (!path && SKIP_SECTIONS.has(key)) continue
      if (typeof value === 'string') {
        if (!SKIP_KEYS.has(full)) node[key] = swapOrganizationText(value, locale, words)
      } else {
        walk(value, full)
      }
    }
  }
  walk(messages, '')
}
