import type { Staff } from '@/services/staff'
import type { CardStudent } from '@/services/staffCardDesigns'
import { formatDate } from '@/utils/date'

/**
 * HRM > Staff cards, Students > Student cards and Examination > Certificate
 * designs: what can be placed on a card and where each value comes from. Positions and sizes are in mm from
 * the card's top-left corner; font sizes in pt. Kept in step with
 * StaffCardDesign::TEXT_FIELDS / STUDENT_TEXT_FIELDS / CERTIFICATE_TEXT_FIELDS / IMAGE_FIELDS.
 */
export type CardFor = 'staff' | 'student' | 'certificate'

export const TEXT_FIELD_TYPES = [
  'full_name',
  'other_name',
  'employee_code',
  'position',
  'department',
  'phone',
  'email',
  'gender',
  'date_of_birth',
  'hire_date',
  'expiry_date',
  'text',
] as const
/** A student card's text fields: class, course, program and year come from their current enrollment. */
export const STUDENT_TEXT_FIELD_TYPES = [
  'full_name',
  'english_name',
  'student_code',
  'class_name',
  'course',
  'program',
  'academic_year',
  'phone',
  'guardian_phone',
  'gender',
  'date_of_birth',
  'enrollment_date',
  'expiry_date',
  'text',
] as const
/** A certificate's text fields: Khmer and English side by side (see utils/certificate.ts); the issue date in parts for the printed dots. */
export const CERTIFICATE_TEXT_FIELD_TYPES = [
  'name_km',
  'name_en',
  'gender_km',
  'gender_en',
  'dob_km',
  'dob_en',
  'course',
  'book',
  'exam_date_km',
  'exam_date_en',
  'mention_km',
  'mention_en',
  'score',
  'file_code',
  'student_code',
  'academic_year',
  'issue_day_km',
  'issue_month_km',
  'issue_year_km',
  'issue_date_en',
  'text',
] as const
export const IMAGE_FIELD_TYPES = ['photo', 'qr', 'logo', 'signature'] as const

export type TextFieldType = (typeof TEXT_FIELD_TYPES)[number] | (typeof STUDENT_TEXT_FIELD_TYPES)[number] | (typeof CERTIFICATE_TEXT_FIELD_TYPES)[number]

/** The fields this kind of card can use, text first. */
export const fieldTypesFor = (cardFor: CardFor): CardFieldType[] => [
  ...(cardFor === 'certificate' ? CERTIFICATE_TEXT_FIELD_TYPES : cardFor === 'student' ? STUDENT_TEXT_FIELD_TYPES : TEXT_FIELD_TYPES),
  ...IMAGE_FIELD_TYPES,
]
export type ImageFieldType = (typeof IMAGE_FIELD_TYPES)[number]
export type CardFieldType = TextFieldType | ImageFieldType
export type CardAlign = 'left' | 'center' | 'right'
export type CardShape = 'rect' | 'rounded' | 'circle'

export interface CardField {
  id: string
  type: CardFieldType
  x: number
  y: number
  w: number
  h: number
  font_size?: number
  color?: string
  bold?: boolean
  align?: CardAlign
  /** Free text only. */
  text?: string
  /** Staff photo only. */
  shape?: CardShape
  /** Text only: a CARD_FONTS name; none = the app's own font. */
  font?: string
}

/** Each field's value for one staff member: text, or an image URL (QR: the text to encode). */
export type CardValues = Partial<Record<CardFieldType, string | null>>

export const isImageField = (type: CardFieldType): type is ImageFieldType => (IMAGE_FIELD_TYPES as readonly string[]).includes(type)

/**
 * Fonts a text field can use: Khmer and Latin Google Fonts (loaded only when
 * a card uses them) plus two fonts every computer has. A font without Khmer
 * letters falls back to Noto Sans Khmer for Khmer text, letter by letter.
 */
export interface CardFont {
  name: string
  khmer: boolean
  /** Has a bold weight; without one the browser thickens the regular. */
  bold: boolean
  system?: boolean
}
export const CARD_FONTS: CardFont[] = [
  { name: 'Battambang', khmer: true, bold: true },
  { name: 'Kantumruy Pro', khmer: true, bold: true },
  { name: 'Hanuman', khmer: true, bold: true },
  { name: 'Suwannaphum', khmer: true, bold: true },
  { name: 'Nokora', khmer: true, bold: true },
  { name: 'Noto Serif Khmer', khmer: true, bold: true },
  { name: 'Content', khmer: true, bold: true },
  { name: 'Siemreap', khmer: true, bold: false },
  { name: 'Moul', khmer: true, bold: false },
  { name: 'Moulpali', khmer: true, bold: false },
  { name: 'Koulen', khmer: true, bold: false },
  { name: 'Bokor', khmer: true, bold: false },
  { name: 'Dangrek', khmer: true, bold: false },
  { name: 'Preahvihear', khmer: true, bold: false },
  { name: 'Angkor', khmer: true, bold: false },
  { name: 'Freehand', khmer: true, bold: false },
  { name: 'Fasthand', khmer: true, bold: false },
  { name: 'Roboto', khmer: false, bold: true },
  { name: 'Open Sans', khmer: false, bold: true },
  { name: 'Montserrat', khmer: false, bold: true },
  { name: 'Poppins', khmer: false, bold: true },
  { name: 'Lato', khmer: false, bold: true },
  { name: 'Oswald', khmer: false, bold: true },
  { name: 'Playfair Display', khmer: false, bold: true },
  { name: 'Merriweather', khmer: false, bold: true },
  { name: 'Arial', khmer: false, bold: true, system: true },
  { name: 'Times New Roman', khmer: false, bold: true, system: true },
]

/** CSS font-family for a field: its font first, then the app's fonts. */
export function cardFontStack(font: string | undefined | null): string | null {
  if (!font || !CARD_FONTS.some((f) => f.name === font)) return null
  return `"${font}", "Inter", "Noto Sans Khmer", sans-serif`
}

const fontCss = new Map<string, Promise<void>>()
/** Adds the Google Fonts stylesheet for these fonts (once each) and waits until it and the font files are loaded. */
export async function loadCardFonts(names: Iterable<string | undefined | null>): Promise<void> {
  const wanted = [...new Set([...names].filter((n): n is string => !!n))]
    .map((n) => CARD_FONTS.find((f) => f.name === n))
    .filter((f): f is CardFont => !!f && !f.system)
  await Promise.all(
    wanted.map((font) => {
      let ready = fontCss.get(font.name)
      if (!ready) {
        ready = new Promise<void>((resolve) => {
          const link = document.createElement('link')
          link.rel = 'stylesheet'
          link.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(font.name).replace(/%20/g, '+')}${font.bold ? ':wght@400;700' : ''}&display=swap`
          link.onload = () => resolve()
          link.onerror = () => resolve()
          document.head.appendChild(link)
        }).then(() =>
          Promise.all([
            document.fonts.load(`400 40px "${font.name}"`, 'Aaសុខ'),
            font.bold ? document.fonts.load(`700 40px "${font.name}"`, 'Aaសុខ') : Promise.resolve(),
          ]).then(
            () => undefined,
            () => undefined,
          ),
        )
        fontCss.set(font.name, ready)
      }
      return ready
    }),
  )
}

/** 1 pt = 0.3528 mm. */
export const PT_TO_MM = 0.3528

export const CARD_PRESETS = [
  { key: 'cr80', width: 85.6, height: 54 },
  { key: 'cr80Portrait', width: 54, height: 85.6 },
  { key: 'a7', width: 105, height: 74 },
] as const

/** Certificate page sizes; new certificates start as A4 portrait. */
export const CERTIFICATE_PRESETS = [
  { key: 'a4Portrait', width: 210, height: 297 },
  { key: 'a4Landscape', width: 297, height: 210 },
  { key: 'a5Portrait', width: 148, height: 210 },
  { key: 'a5Landscape', width: 210, height: 148 },
] as const

const round1 = (n: number) => Math.round(n * 10) / 10

/** A new field of this type, centred on (cx, cy) mm and kept inside the card. */
export function newField(type: CardFieldType, cardW: number, cardH: number, cx?: number, cy?: number): CardField {
  const size: Record<CardFieldType, [number, number]> = {
    full_name: [45, 6],
    other_name: [45, 6],
    employee_code: [30, 5],
    position: [40, 5],
    department: [40, 5],
    phone: [30, 4.5],
    email: [40, 4.5],
    gender: [20, 4.5],
    date_of_birth: [25, 4.5],
    hire_date: [25, 4.5],
    expiry_date: [25, 4.5],
    english_name: [45, 6],
    student_code: [30, 5],
    class_name: [35, 5],
    course: [40, 5],
    program: [40, 5],
    academic_year: [25, 4.5],
    guardian_phone: [30, 4.5],
    enrollment_date: [25, 4.5],
    name_km: [45, 6],
    name_en: [45, 6],
    gender_km: [12, 4.5],
    gender_en: [8, 4.5],
    dob_km: [30, 4.5],
    dob_en: [25, 4.5],
    book: [40, 4.5],
    exam_date_km: [30, 4.5],
    exam_date_en: [25, 4.5],
    mention_km: [20, 4.5],
    mention_en: [20, 4.5],
    score: [12, 4.5],
    file_code: [25, 4.5],
    issue_day_km: [8, 4.5],
    issue_month_km: [15, 4.5],
    issue_year_km: [12, 4.5],
    issue_date_en: [30, 4.5],
    text: [40, 5],
    photo: [22, 28],
    qr: [16, 16],
    logo: [14, 14],
    signature: [22, 10],
  }
  // A page (certificate) gets everything about twice as big as on an ID card.
  const big = Math.max(cardW, cardH) > 150 ? 2 : 1
  const [w0, h0] = size[type]
  const w = Math.min(w0 * big, cardW)
  const h = Math.min(h0 * big, cardH)
  const x = Math.min(Math.max((cx ?? cardW / 2) - w / 2, 0), cardW - w)
  const y = Math.min(Math.max((cy ?? cardH / 2) - h / 2, 0), cardH - h)
  const field: CardField = { id: Math.random().toString(36).slice(2, 10), type, x: round1(x), y: round1(y), w, h }
  if (!isImageField(type)) {
    const name = type === 'full_name' || type === 'other_name' || type === 'english_name' || type === 'name_km' || type === 'name_en'
    Object.assign(field, { font_size: (name ? 10 : 7) * big, color: '#111111', bold: name, align: 'left' })
  }
  if (type === 'photo') field.shape = 'rounded'
  return field
}

export interface CardSource {
  staff: Staff | null
  logoUrl: string | null
  /** The design's signature / stamp; falls back to the staff member's own signature. */
  signatureUrl: string | null
  /** dd-mm-yyyy, set when printing. */
  expiry: string | null
  genderLabel: (gender: string) => string
  /** Shown when there's no staff member (designing with no staff to pick). */
  sample: Partial<Record<TextFieldType, string>>
}

export interface StudentCardSource {
  student: CardStudent | null
  logoUrl: string | null
  /** The design's signature / stamp (students have none of their own). */
  signatureUrl: string | null
  expiry: string | null
  genderLabel: (gender: string) => string
  sample: Partial<Record<TextFieldType, string>>
}

export function studentCardValues(source: StudentCardSource): CardValues {
  const s = source.student
  if (!s) {
    return { ...source.sample, expiry_date: source.expiry, qr: source.sample.student_code ?? 'STUDENT', logo: source.logoUrl, signature: source.signatureUrl, photo: null }
  }
  const date = (value: string | null | undefined) => (value ? formatDate(value) : null)
  return {
    full_name: s.full_name,
    english_name: s.english_name,
    student_code: s.student_code,
    class_name: s.class_name,
    course: s.course,
    program: s.program,
    academic_year: s.academic_year,
    phone: s.phone,
    guardian_phone: s.guardian_phone,
    gender: s.gender ? source.genderLabel(s.gender) : null,
    date_of_birth: date(s.date_of_birth),
    enrollment_date: date(s.enrollment_date),
    expiry_date: source.expiry,
    photo: s.photo_url,
    qr: s.student_code,
    logo: source.logoUrl,
    signature: source.signatureUrl,
  }
}

export function staffCardValues(source: CardSource): CardValues {
  const s = source.staff
  if (!s) {
    return { ...source.sample, expiry_date: source.expiry, qr: source.sample.employee_code ?? 'STAFF', logo: source.logoUrl, signature: source.signatureUrl, photo: null }
  }
  const date = (value: string | null | undefined) => (value ? formatDate(value) : null)
  return {
    full_name: s.full_name,
    other_name: s.other_name,
    employee_code: s.employee_code,
    position: s.position?.name ?? null,
    department: s.department?.name ?? null,
    phone: s.phone,
    email: s.email,
    gender: s.gender ? source.genderLabel(s.gender) : null,
    date_of_birth: date(s.date_of_birth),
    hire_date: date(s.hire_date),
    expiry_date: source.expiry,
    photo: s.photo_url,
    qr: s.employee_code,
    logo: source.logoUrl,
    signature: source.signatureUrl ?? s.signature_url,
  }
}
