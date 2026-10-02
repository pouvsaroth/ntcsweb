/**
 * Draws a simple report table onto a canvas and saves it as a PNG — for
 * "Export as image" buttons. Drawn directly (not a DOM screenshot) so every
 * row is included regardless of scroll position, text stays sharp, and
 * Khmer renders in the page's own font with no extra dependency.
 *
 * Text is never cut off: each column widens to fit its longest text, and a
 * column given a maxWidth wraps onto extra lines instead (the row grows).
 */
export interface TableImageColumn {
  label: string
  align?: 'left' | 'right'
  /** Minimum width — the column widens to fit its text. */
  width: number
  /** Beyond this, text wraps onto more lines rather than widening further. */
  maxWidth?: number
}

export type TableImageTone = 'success' | 'danger' | 'warning' | 'neutral'

export interface TableImageCell {
  text: string
  /** Smaller grey second line under the main text (e.g. course/class). */
  subtext?: string
  /** Red highlight box with white text. */
  alert?: boolean
  bold?: boolean
  /** Draws the text as a coloured pill, like BaseBadge. */
  badge?: TableImageTone
}

export interface TableImageOptions {
  title: string
  subtitle?: string
  columns: TableImageColumn[]
  rows: TableImageCell[][]
  emptyText: string
  fileName: string
}

const PADDING = 24
const CELL_X = 12
const CELL_Y = 12
const HEADER_HEIGHT = 40
const ROW_HEIGHT = 48
const LINE_HEIGHT = 20
const SUBTEXT_LINE_HEIGHT = 17
const SUBTITLE_LINE_HEIGHT = 18
/** Extra room a highlight box / badge takes around its text. */
const BOX_X = 8
// Browsers cap a canvas at roughly 32k px per side.
const MAX_CANVAS_PX = 32_000

const BADGE_COLORS: Record<TableImageTone, { bg: string; fg: string }> = {
  success: { bg: '#dcfce7', fg: '#166534' },
  danger: { bg: '#fee2e2', fg: '#991b1b' },
  warning: { bg: '#fef3c7', fg: '#92400e' },
  neutral: { bg: '#f5f5f5', fg: '#404040' },
}

/** Word pieces (keeping their trailing spaces) — Intl.Segmenter also splits Khmer, which has no spaces. */
function segments(text: string, granularity: 'word' | 'grapheme'): string[] {
  if (typeof Intl !== 'undefined' && 'Segmenter' in Intl) {
    return Array.from(new Intl.Segmenter(undefined, { granularity }).segment(text), (s) => s.segment)
  }
  return granularity === 'word' ? text.split(/(?<=\s)/) : Array.from(text)
}

/** Breaks text into lines no wider than maxWidth, splitting overlong words if needed. */
function wrap(ctx: CanvasRenderingContext2D, text: string, maxWidth: number): string[] {
  const lines: string[] = []
  for (const paragraph of text.split('\n')) {
    let line = ''
    for (const piece of segments(paragraph, 'word')) {
      const candidate = line + piece
      if (ctx.measureText(candidate.trimEnd()).width <= maxWidth) {
        line = candidate
        continue
      }
      if (line.trim()) lines.push(line.trimEnd())
      line = ''
      // A single piece wider than the column: break it between characters.
      for (const char of segments(piece, 'grapheme')) {
        if (line && ctx.measureText((line + char).trimEnd()).width > maxWidth) {
          lines.push(line.trimEnd())
          line = ''
        }
        line += char
      }
    }
    lines.push(line.trimEnd())
  }
  return lines
}

function cellFont(cell: TableImageCell, font: string): string {
  return `${cell.bold || cell.alert ? '600 ' : ''}${cell.badge ? '12px' : '14px'} ${font}`
}

function boxPadding(cell: TableImageCell): number {
  return cell.alert || cell.badge ? BOX_X * 2 : 0
}

export async function exportTableAsImage(options: TableImageOptions): Promise<void> {
  await document.fonts?.ready

  const font = getComputedStyle(document.body).fontFamily || 'sans-serif'
  const ctx = document.createElement('canvas').getContext('2d')
  if (!ctx) throw new Error('Canvas is not available.')

  // --- Measure: widen each column to its longest text (up to maxWidth) ---------
  const widths = options.columns.map((column, columnIndex) => {
    ctx.font = `600 13px ${font}`
    const labelWidth = ctx.measureText(column.label).width + CELL_X * 2
    let contentWidth = 0
    for (const row of options.rows) {
      const cell = row[columnIndex]
      if (!cell) continue
      ctx.font = cellFont(cell, font)
      contentWidth = Math.max(contentWidth, ctx.measureText(cell.text).width + boxPadding(cell))
      if (cell.subtext) {
        ctx.font = `12px ${font}`
        contentWidth = Math.max(contentWidth, ctx.measureText(cell.subtext).width)
      }
    }
    contentWidth += CELL_X * 2
    if (column.maxWidth) contentWidth = Math.min(contentWidth, column.maxWidth)
    return Math.ceil(Math.max(column.width, labelWidth, contentWidth))
  })
  const tableWidth = widths.reduce((sum, w) => sum + w, 0)
  const width = tableWidth + PADDING * 2

  // --- Lay out each cell's lines and each row's height ---------------------------
  const layout = options.rows.map((row) => {
    const cells = row.map((cell, columnIndex) => {
      const inner = (widths[columnIndex] ?? 0) - CELL_X * 2
      ctx.font = cellFont(cell, font)
      const lines = wrap(ctx, cell.text, inner - boxPadding(cell))
      ctx.font = `12px ${font}`
      const subLines = cell.subtext ? wrap(ctx, cell.subtext, inner) : []
      return { cell, lines, subLines, height: lines.length * LINE_HEIGHT + subLines.length * SUBTEXT_LINE_HEIGHT }
    })
    const height = Math.max(ROW_HEIGHT, ...cells.map((c) => c.height + CELL_Y * 2))
    return { cells, height }
  })

  ctx.font = `13px ${font}`
  const subtitleLines = options.subtitle ? wrap(ctx, options.subtitle, tableWidth) : []
  const titleBlock = 44 + subtitleLines.length * SUBTITLE_LINE_HEIGHT + (subtitleLines.length ? 6 : 0)
  const bodyHeight = layout.length ? layout.reduce((sum, r) => sum + r.height, 0) : ROW_HEIGHT
  const height = PADDING + titleBlock + HEADER_HEIGHT + bodyHeight + PADDING
  const scale = Math.max(1, Math.min(2, MAX_CANVAS_PX / height, MAX_CANVAS_PX / width))

  // --- Draw --------------------------------------------------------------------
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(width * scale)
  canvas.height = Math.round(height * scale)
  const draw = canvas.getContext('2d')
  if (!draw) throw new Error('Canvas is not available.')
  draw.scale(scale, scale)
  draw.textBaseline = 'middle'

  draw.fillStyle = '#ffffff'
  draw.fillRect(0, 0, width, height)

  let y = PADDING
  draw.fillStyle = '#171717'
  draw.font = `600 20px ${font}`
  draw.fillText(options.title, PADDING, y + 12)
  draw.fillStyle = '#737373'
  draw.font = `13px ${font}`
  subtitleLines.forEach((line, i) => draw.fillText(line, PADDING, y + 38 + i * SUBTITLE_LINE_HEIGHT))
  y += titleBlock

  // Header row
  draw.fillStyle = '#f5f5f5'
  draw.fillRect(PADDING, y, tableWidth, HEADER_HEIGHT)
  draw.font = `600 13px ${font}`
  draw.fillStyle = '#525252'
  let x = PADDING
  options.columns.forEach((column, columnIndex) => {
    const w = widths[columnIndex] ?? 0
    draw.textAlign = column.align === 'right' ? 'right' : 'left'
    draw.fillText(column.label, column.align === 'right' ? x + w - CELL_X : x + CELL_X, y + HEADER_HEIGHT / 2)
    x += w
  })
  y += HEADER_HEIGHT

  if (layout.length === 0) {
    draw.textAlign = 'center'
    draw.fillStyle = '#a3a3a3'
    draw.font = `14px ${font}`
    draw.fillText(options.emptyText, PADDING + tableWidth / 2, y + ROW_HEIGHT / 2)
  }

  layout.forEach((row, index) => {
    if (index % 2 === 1) {
      draw.fillStyle = '#fafafa'
      draw.fillRect(PADDING, y, tableWidth, row.height)
    }

    let cellX = PADDING
    row.cells.forEach(({ cell, lines, subLines, height: contentHeight }, columnIndex) => {
      const column = options.columns[columnIndex]
      const w = widths[columnIndex] ?? 0
      if (!column) return
      const right = column.align === 'right'
      draw.textAlign = right ? 'right' : 'left'
      draw.font = cellFont(cell, font)
      const textX = right ? cellX + w - CELL_X - (cell.badge ? BOX_X : 0) : cellX + CELL_X + (cell.badge ? BOX_X : 0)
      // Vertically centre the cell's block of lines within the row.
      let lineY = y + (row.height - contentHeight) / 2 + LINE_HEIGHT / 2

      lines.forEach((line) => {
        const box = cell.alert ? { bg: '#dc2626', fg: '#ffffff' } : cell.badge ? BADGE_COLORS[cell.badge] : null
        if (box) {
          const boxWidth = draw.measureText(line).width + BOX_X * 2
          const boxX = right ? textX - boxWidth + BOX_X : textX - BOX_X
          draw.fillStyle = box.bg
          draw.beginPath()
          draw.roundRect(boxX, lineY - 11, boxWidth, 22, cell.badge ? 11 : 4)
          draw.fill()
          draw.fillStyle = box.fg
        } else {
          draw.fillStyle = '#262626'
        }
        draw.fillText(line, textX, lineY)
        lineY += LINE_HEIGHT
      })

      if (subLines.length) {
        draw.font = `12px ${font}`
        draw.fillStyle = '#a3a3a3'
        lineY -= LINE_HEIGHT / 2 - SUBTEXT_LINE_HEIGHT / 2
        subLines.forEach((line) => {
          draw.fillText(line, textX, lineY)
          lineY += SUBTEXT_LINE_HEIGHT
        })
      }
      cellX += w
    })

    draw.strokeStyle = '#e5e5e5'
    draw.beginPath()
    draw.moveTo(PADDING, y + row.height - 0.5)
    draw.lineTo(PADDING + tableWidth, y + row.height - 0.5)
    draw.stroke()
    y += row.height
  })

  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'))
  if (!blob) throw new Error('Could not create the image.')

  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = options.fileName
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
