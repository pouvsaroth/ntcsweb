import QRCode from 'qrcode'

import { isImageField, PT_TO_MM, type CardField, type CardValues } from '@/utils/staffCard'

/**
 * HRM > Staff cards: draws cards onto a canvas at 300 dpi — one card (front,
 * back or both side by side) as a PNG, or many cards laid out on A4 / A5
 * sheets for printing and cutting. Same layout rules as StaffCardCanvas.vue
 * (mm positions, pt font sizes), so what was designed is what prints.
 */
export const DPI = 300
const PX_PER_MM = DPI / 25.4

export type CardSide = 'front' | 'back'
export type SheetSize = 'A4' | 'A5'

export interface RenderDesign {
  width_mm: number
  height_mm: number
  front_background_url: string | null
  back_background_url: string | null
  front_fields: CardField[]
  back_fields: CardField[]
}

// ---- Images ------------------------------------------------------------------
/**
 * Uploaded files are read from this site's own /storage/ path (the admin site
 * serves /storage itself; in dev Vite proxies it), so the canvas isn't
 * "tainted" by another origin and can still be saved as a PNG.
 */
function sameOrigin(url: string): string {
  try {
    const parsed = new URL(url, window.location.href)
    if (parsed.pathname.startsWith('/storage/')) return parsed.pathname
  } catch {
    // not a URL — use as is
  }
  return url
}

const imageCache = new Map<string, Promise<CanvasImageSource | null>>()
function loadImage(url: string | null | undefined): Promise<CanvasImageSource | null> {
  if (!url) return Promise.resolve(null)
  let cached = imageCache.get(url)
  if (!cached) {
    cached = (async () => {
      try {
        if (url.startsWith('data:')) {
          const img = new Image()
          img.src = url
          await img.decode()
          return img
        }
        const response = await fetch(sameOrigin(url), { credentials: 'same-origin' })
        if (!response.ok) return null
        return await createImageBitmap(await response.blob())
      } catch {
        return null
      }
    })()
    imageCache.set(url, cached)
  }
  return cached
}

const sourceSize = (img: CanvasImageSource) => {
  const any = img as { naturalWidth?: number; naturalHeight?: number; width: number; height: number }
  return { w: any.naturalWidth || any.width, h: any.naturalHeight || any.height }
}

// ---- Text ----------------------------------------------------------------------
const segmenter = typeof Intl !== 'undefined' && 'Segmenter' in Intl ? new Intl.Segmenter(undefined, { granularity: 'grapheme' }) : null
const graphemes = (text: string) => (segmenter ? Array.from(segmenter.segment(text), (s) => s.segment) : Array.from(text))

/** Word-wrap to `maxWidth`; a word too long for one line (e.g. Khmer, which has no spaces) breaks between letters. */
function wrap(ctx: CanvasRenderingContext2D, text: string, maxWidth: number): string[] {
  const fits = (s: string) => ctx.measureText(s).width <= maxWidth
  const lines: string[] = []
  for (const paragraph of text.split('\n')) {
    let line = ''
    for (const word of paragraph.split(/(\s+)/)) {
      if (!word) continue
      if (fits(line + word)) {
        line += word
        continue
      }
      if (!word.trim()) {
        lines.push(line.trimEnd())
        line = ''
        continue
      }
      if (line.trim()) {
        lines.push(line.trimEnd())
        line = ''
      }
      if (fits(word)) {
        line = word
        continue
      }
      for (const g of graphemes(word)) {
        if (line && !fits(line + g)) {
          lines.push(line)
          line = g
        } else {
          line += g
        }
      }
    }
    lines.push(line.trimEnd())
  }
  return lines
}

function fontFamily(): string {
  return getComputedStyle(document.body).fontFamily || 'sans-serif'
}

// ---- One side ------------------------------------------------------------------
async function drawSide(ctx: CanvasRenderingContext2D, x0: number, y0: number, design: RenderDesign, side: CardSide, values: CardValues, outline: boolean): Promise<void> {
  const s = PX_PER_MM
  const W = design.width_mm * s
  const H = design.height_mm * s
  const family = fontFamily()

  ctx.save()
  ctx.beginPath()
  ctx.rect(x0, y0, W, H)
  ctx.clip()
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(x0, y0, W, H)

  const background = await loadImage(side === 'front' ? design.front_background_url : design.back_background_url)
  if (background) ctx.drawImage(background, x0, y0, W, H)

  const qr = values.qr ? await QRCode.toDataURL(values.qr, { margin: 0, width: 600, errorCorrectionLevel: 'M' }).catch(() => null) : null

  for (const field of side === 'front' ? design.front_fields : design.back_fields) {
    const fx = x0 + field.x * s
    const fy = y0 + field.y * s
    const fw = field.w * s
    const fh = field.h * s

    if (isImageField(field.type)) {
      const img = await loadImage(field.type === 'qr' ? qr : values[field.type])
      if (!img) continue
      const { w: iw, h: ih } = sourceSize(img)
      if (!iw || !ih) continue
      ctx.save()
      ctx.beginPath()
      if (field.type === 'photo' && field.shape === 'circle') {
        ctx.ellipse(fx + fw / 2, fy + fh / 2, fw / 2, fh / 2, 0, 0, Math.PI * 2)
      } else if (field.type === 'photo' && (field.shape ?? 'rounded') === 'rounded') {
        ctx.roundRect(fx, fy, fw, fh, 2 * s)
      } else {
        ctx.rect(fx, fy, fw, fh)
      }
      ctx.clip()
      // The photo fills its box (cropped); logo, QR and signature fit inside it.
      const ratio = field.type === 'photo' ? Math.max(fw / iw, fh / ih) : Math.min(fw / iw, fh / ih)
      const dw = iw * ratio
      const dh = ih * ratio
      ctx.drawImage(img, fx + (fw - dw) / 2, fy + (fh - dh) / 2, dw, dh)
      ctx.restore()
      continue
    }

    const text = field.type === 'text' ? (field.text ?? '') : (values[field.type] ?? '')
    if (!text) continue
    const size = (field.font_size ?? 8) * PT_TO_MM * s
    ctx.save()
    ctx.beginPath()
    ctx.rect(fx, fy, fw, fh)
    ctx.clip()
    ctx.font = `${field.bold ? 700 : 400} ${size}px ${family}`
    ctx.fillStyle = field.color ?? '#111111'
    ctx.textBaseline = 'middle'
    const align = field.align ?? 'left'
    ctx.textAlign = align
    const tx = align === 'center' ? fx + fw / 2 : align === 'right' ? fx + fw : fx
    const lineHeight = size * 1.15
    const lines = wrap(ctx, text, fw)
    // Vertically centred in its box, like the designer.
    let ty = fy + fh / 2 - ((lines.length - 1) * lineHeight) / 2
    for (const line of lines) {
      ctx.fillText(line, tx, ty)
      ty += lineHeight
    }
    ctx.restore()
  }
  ctx.restore()

  if (outline) {
    // A hairline to cut along.
    ctx.strokeStyle = '#c8c8c8'
    ctx.lineWidth = 1
    ctx.strokeRect(x0 - 0.5, y0 - 0.5, W + 1, H + 1)
  }
}

async function fontsReady(): Promise<void> {
  const family = fontFamily()
  await Promise.all([document.fonts.load(`400 40px ${family}`, 'Aaសុខ'), document.fonts.load(`700 40px ${family}`, 'Aaសុខ')]).catch(() => undefined)
  await document.fonts.ready
}

const toBlob = (canvas: HTMLCanvasElement) =>
  new Promise<Blob>((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('Could not create the image.'))), 'image/png'))

// ---- One card --------------------------------------------------------------------
/** One staff member's card at 300 dpi: one side, or front and back side by side. */
export async function renderCardImage(design: RenderDesign, values: CardValues, sides: CardSide[]): Promise<Blob> {
  await fontsReady()
  const gap = 4 * PX_PER_MM
  const W = design.width_mm * PX_PER_MM
  const H = design.height_mm * PX_PER_MM
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(W * sides.length + gap * (sides.length - 1))
  canvas.height = Math.round(H)
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new Error('Canvas is not available in this browser.')
  for (const [i, side] of sides.entries()) await drawSide(ctx, Math.round(i * (W + gap)), 0, design, side, values, false)
  return toBlob(canvas)
}

// ---- Sheets ------------------------------------------------------------------------
const SHEETS: Record<SheetSize, [number, number]> = { A4: [210, 297], A5: [148, 210] }
const MARGIN = 8
const GAP = 3

export interface SheetLayout {
  size: SheetSize
  landscape: boolean
  cols: number
  rows: number
  perPage: number
  /** For printImages(): e.g. "A5 landscape". */
  pageSize: string
}

/** How many cards fit on the sheet, turning it sideways when that fits more. */
export function sheetLayout(size: SheetSize, cardW: number, cardH: number): SheetLayout {
  const [short, long] = SHEETS[size]
  const fit = (pw: number, ph: number) => {
    const cols = Math.max(Math.floor((pw - 2 * MARGIN + GAP) / (cardW + GAP)), 0)
    const rows = Math.max(Math.floor((ph - 2 * MARGIN + GAP) / (cardH + GAP)), 0)
    return { cols, rows, perPage: cols * rows }
  }
  const portrait = fit(short, long)
  const landscape = fit(long, short)
  const useLandscape = landscape.perPage > portrait.perPage
  const best = useLandscape ? landscape : portrait
  return { size, landscape: useLandscape, ...best, pageSize: `${size} ${useLandscape ? 'landscape' : 'portrait'}` }
}

/** A5 when at least 4 cards fit on it, otherwise A4. */
export function autoSheet(cardW: number, cardH: number): SheetSize {
  return sheetLayout('A5', cardW, cardH).perPage >= 4 ? 'A5' : 'A4'
}

/**
 * Every card on sheets, one PNG per page. With both sides, each front page is
 * followed by its back page with the columns mirrored, so printing double-
 * sided (flip on the long edge) puts each back behind its own front.
 */
export async function renderSheets(
  design: RenderDesign,
  cards: CardValues[],
  sides: CardSide[],
  size: SheetSize,
  onProgress?: (done: number, total: number) => void,
): Promise<{ blobs: Blob[]; layout: SheetLayout }> {
  const layout = sheetLayout(size, design.width_mm, design.height_mm)
  if (!layout.perPage) throw new Error('card-too-big')
  await fontsReady()
  const [short, long] = SHEETS[size]
  const pw = layout.landscape ? long : short
  const ph = layout.landscape ? short : long
  // The grid is centred on the page.
  const gridW = layout.cols * design.width_mm + (layout.cols - 1) * GAP
  const gridH = layout.rows * design.height_mm + (layout.rows - 1) * GAP
  const left = (pw - gridW) / 2
  const top = (ph - gridH) / 2

  const pages: { side: CardSide; cards: CardValues[] }[] = []
  for (let i = 0; i < cards.length; i += layout.perPage) {
    for (const side of sides) pages.push({ side, cards: cards.slice(i, i + layout.perPage) })
  }

  const blobs: Blob[] = []
  for (const [p, page] of pages.entries()) {
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(pw * PX_PER_MM)
    canvas.height = Math.round(ph * PX_PER_MM)
    const ctx = canvas.getContext('2d')
    if (!ctx) throw new Error('Canvas is not available in this browser.')
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    const mirror = page.side === 'back' && sides.length === 2
    for (const [i, values] of page.cards.entries()) {
      const row = Math.floor(i / layout.cols)
      const col0 = i % layout.cols
      const col = mirror ? layout.cols - 1 - col0 : col0
      const x = (left + col * (design.width_mm + GAP)) * PX_PER_MM
      const y = (top + row * (design.height_mm + GAP)) * PX_PER_MM
      await drawSide(ctx, Math.round(x), Math.round(y), design, page.side, values, true)
    }
    blobs.push(await toBlob(canvas))
    onProgress?.(p + 1, pages.length)
  }
  return { blobs, layout }
}
