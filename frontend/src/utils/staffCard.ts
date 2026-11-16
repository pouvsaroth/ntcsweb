import type { Staff } from '@/services/staff'
import { formatDate } from '@/utils/date'

/**
 * HRM > Staff cards: what can be placed on a card and where each value comes
 * from. Positions and sizes are in mm from the card's top-left corner; font
 * sizes in pt. Kept in step with StaffCardDesign::TEXT_FIELDS / IMAGE_FIELDS.
 */
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
export const IMAGE_FIELD_TYPES = ['photo', 'qr', 'logo', 'signature'] as const

export type TextFieldType = (typeof TEXT_FIELD_TYPES)[number]
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
    text: [40, 5],
    photo: [22, 28],
    qr: [16, 16],
    logo: [14, 14],
    signature: [22, 10],
  }
  const [w0, h0] = size[type]
  const w = Math.min(w0, cardW)
  const h = Math.min(h0, cardH)
  const x = Math.min(Math.max((cx ?? cardW / 2) - w / 2, 0), cardW - w)
  const y = Math.min(Math.max((cy ?? cardH / 2) - h / 2, 0), cardH - h)
  const field: CardField = { id: Math.random().toString(36).slice(2, 10), type, x: round1(x), y: round1(y), w, h }
  if (!isImageField(type)) {
    Object.assign(field, { font_size: type === 'full_name' || type === 'other_name' ? 10 : 7, color: '#111111', bold: type === 'full_name' || type === 'other_name', align: 'left' })
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
