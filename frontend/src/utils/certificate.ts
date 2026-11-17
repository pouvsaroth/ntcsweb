import type { CardValues } from '@/utils/staffCard'

/**
 * Examination > Certificate designs: what a certificate's fields show, in
 * Khmer and English side by side, matching the school's printed
 * certificate — Khmer dates "១៤ សីហា ២០០២", English "14-AUG-2002", the
 * English name and mention in capitals, sex ប្រុស/ស្រី and M/F.
 */

const KM_DIGITS = '០១២៣៤៥៦៧៨៩'
const KM_MONTHS = ['មករា', 'កុម្ភៈ', 'មីនា', 'មេសា', 'ឧសភា', 'មិថុនា', 'កក្កដា', 'សីហា', 'កញ្ញា', 'តុលា', 'វិច្ឆិកា', 'ធ្នូ']
const EN_MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC']

/** "2019" → "២០១៩". */
export const khmerDigits = (value: string): string => value.replace(/[0-9]/g, (d) => KM_DIGITS[Number(d)]!)

/** [day "09", month 1–12, year "2019"] from an ISO date (time ignored), or null. */
function parts(iso: string | null | undefined): [string, number, string] | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso ?? '')
  if (!match) return null
  const month = Number(match[2])
  return month >= 1 && month <= 12 ? [match[3]!, month, match[1]!] : null
}

/** "2002-08-14" → "១៤ សីហា ២០០២". */
export function khmerDate(iso: string | null | undefined): string | null {
  const p = parts(iso)
  return p ? `${khmerDigits(p[0])} ${KM_MONTHS[p[1] - 1]} ${khmerDigits(p[2])}` : null
}

/** "2002-08-14" → "14-AUG-2002". */
export function englishCertificateDate(iso: string | null | undefined): string | null {
  const p = parts(iso)
  return p ? `${p[0]}-${EN_MONTHS[p[1] - 1]}-${p[2]}` : null
}

export type CertificateMention = 'excellent' | 'very_good' | 'good' | 'fail'

const MENTION_KM: Record<CertificateMention, string> = { excellent: 'ល្អប្រសើរ', very_good: 'ល្អណាស់', good: 'ល្អ', fail: 'ធ្លាក់' }
const MENTION_EN: Record<CertificateMention, string> = { excellent: 'EXCELLENT', very_good: 'VERY GOOD', good: 'GOOD', fail: 'FAIL' }

function sex(gender: string | null | undefined): 'm' | 'f' | null {
  const g = (gender ?? '').trim().toLowerCase()
  if (g === 'male' || g === 'm') return 'm'
  if (g === 'female' || g === 'f') return 'f'
  return null
}

/** One passed student's certificate details (raw — dates ISO). */
export interface CertificateSource {
  name_km: string | null
  name_en: string | null
  gender: string | null
  date_of_birth: string | null
  course: string | null
  book: string | null
  exam_date: string | null
  mention: CertificateMention | null
  score: string | null
  file_code: string | null
  student_code: string | null
  academic_year: string | null
  photo_url: string | null
  /** Typed on the Certificate list when printing (ISO). */
  issue_date: string | null
  logoUrl: string | null
  signatureUrl: string | null
}

export function certificateValues(s: CertificateSource): CardValues {
  const g = sex(s.gender)
  const issue = parts(s.issue_date)
  return {
    name_km: s.name_km,
    name_en: s.name_en ? s.name_en.toUpperCase() : null,
    gender_km: g === 'm' ? 'ប្រុស' : g === 'f' ? 'ស្រី' : null,
    gender_en: g === 'm' ? 'M' : g === 'f' ? 'F' : null,
    dob_km: khmerDate(s.date_of_birth),
    dob_en: englishCertificateDate(s.date_of_birth),
    course: s.course,
    book: s.book,
    exam_date_km: khmerDate(s.exam_date),
    exam_date_en: englishCertificateDate(s.exam_date),
    mention_km: s.mention ? MENTION_KM[s.mention] : null,
    mention_en: s.mention ? MENTION_EN[s.mention] : null,
    score: s.score,
    file_code: s.file_code,
    student_code: s.student_code,
    academic_year: s.academic_year,
    issue_day_km: issue ? khmerDigits(issue[0]) : null,
    issue_month_km: issue ? KM_MONTHS[issue[1] - 1]! : null,
    issue_year_km: issue ? khmerDigits(issue[2]) : null,
    issue_date_en: englishCertificateDate(s.issue_date),
    photo: s.photo_url,
    qr: s.file_code || s.student_code,
    logo: s.logoUrl,
    signature: s.signatureUrl,
  }
}

/** Today as ISO (local time) — the default issue date. */
export function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** Made-up details to design with — the example on the school's own certificate. */
export function sampleCertificate(logoUrl: string | null = null, signatureUrl: string | null = null): CertificateSource {
  return {
    name_km: 'សឹម ឧត្តម',
    name_en: 'Seom Udom',
    gender: 'male',
    date_of_birth: '2002-08-14',
    course: 'Computer',
    book: 'Microsoft Word 2016',
    exam_date: '2019-07-09',
    mention: 'good',
    score: '78',
    file_code: 'NTS-000232',
    student_code: 'STU0001',
    academic_year: '2019-2020',
    photo_url: null,
    issue_date: todayIso(),
    logoUrl,
    signatureUrl,
  }
}
