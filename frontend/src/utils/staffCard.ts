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
}

/** Each field's value for one staff member: text, or an image URL (QR: the text to encode). */
export type CardValues = Partial<Record<CardFieldType, string | null>>

export const isImageField = (type: CardFieldType): type is ImageFieldType => (IMAGE_FIELD_TYPES as readonly string[]).includes(type)

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
