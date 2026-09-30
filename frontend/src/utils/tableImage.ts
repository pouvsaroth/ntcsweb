/**
 * Draws a simple report table onto a canvas and saves it as a PNG — for
 * "Export as image" buttons. Drawn directly (not a DOM screenshot) so every
 * row is included regardless of scroll position, text stays sharp, and
 * Khmer renders in the page's own font with no extra dependency.
 */
export interface TableImageColumn {
  label: string
  align?: 'left' | 'right'
  width: number
}

export interface TableImageCell {
  text: string
  /** Smaller grey second line under the main text (e.g. course/class). */
  subtext?: string
  /** Red highlight box with white text. */
  alert?: boolean
  bold?: boolean
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
const HEADER_HEIGHT = 40
const ROW_HEIGHT = 48
// Browsers cap a canvas at roughly 32k px per side.
const MAX_CANVAS_PX = 32_000

function truncate(ctx: CanvasRenderingContext2D, text: string, maxWidth: number): string {
  if (ctx.measureText(text).width <= maxWidth) return text
  let end = text.length
  while (end > 0 && ctx.measureText(`${text.slice(0, end)}…`).width > maxWidth) end--
  return `${text.slice(0, end)}…`
}

export async function exportTableAsImage(options: TableImageOptions): Promise<void> {
  await document.fonts?.ready

  const font = getComputedStyle(document.body).fontFamily || 'sans-serif'
  const tableWidth = options.columns.reduce((sum, c) => sum + c.width, 0)
  const width = tableWidth + PADDING * 2
  const titleBlock = options.subtitle ? 64 : 44
  const bodyRows = Math.max(options.rows.length, 1)
  const height = PADDING + titleBlock + HEADER_HEIGHT + bodyRows * ROW_HEIGHT + PADDING
  const scale = Math.max(1, Math.min(2, MAX_CANVAS_PX / height))

  const canvas = document.createElement('canvas')
  canvas.width = Math.round(width * scale)
  canvas.height = Math.round(height * scale)
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new Error('Canvas is not available.')
  ctx.scale(scale, scale)
  ctx.textBaseline = 'middle'

  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, width, height)

  let y = PADDING
  ctx.fillStyle = '#171717'
  ctx.font = `600 20px ${font}`
  ctx.fillText(options.title, PADDING, y + 12)
  if (options.subtitle) {
    ctx.fillStyle = '#737373'
    ctx.font = `13px ${font}`
    ctx.fillText(truncate(ctx, options.subtitle, tableWidth), PADDING, y + 38)
  }
  y += titleBlock

  // Header row
  ctx.fillStyle = '#f5f5f5'
  ctx.fillRect(PADDING, y, tableWidth, HEADER_HEIGHT)
  ctx.font = `600 13px ${font}`
  ctx.fillStyle = '#525252'
  let x = PADDING
  for (const column of options.columns) {
    const label = truncate(ctx, column.label, column.width - CELL_X * 2)
    if (column.align === 'right') {
      ctx.textAlign = 'right'
      ctx.fillText(label, x + column.width - CELL_X, y + HEADER_HEIGHT / 2)
    } else {
      ctx.textAlign = 'left'
      ctx.fillText(label, x + CELL_X, y + HEADER_HEIGHT / 2)
    }
    x += column.width
  }
  y += HEADER_HEIGHT

  if (options.rows.length === 0) {
    ctx.textAlign = 'center'
    ctx.fillStyle = '#a3a3a3'
    ctx.font = `14px ${font}`
    ctx.fillText(options.emptyText, PADDING + tableWidth / 2, y + ROW_HEIGHT / 2)
  }

  options.rows.forEach((row, index) => {
    if (index % 2 === 1) {
      ctx.fillStyle = '#fafafa'
      ctx.fillRect(PADDING, y, tableWidth, ROW_HEIGHT)
    }

    let cellX = PADDING
    row.forEach((cell, columnIndex) => {
      const column = options.columns[columnIndex]
      if (!column) return
      const right = column.align === 'right'
      ctx.textAlign = right ? 'right' : 'left'
      ctx.font = `${cell.bold || cell.alert ? '600 ' : ''}14px ${font}`
      const text = truncate(ctx, cell.text, column.width - CELL_X * 2)
      const textX = right ? cellX + column.width - CELL_X : cellX + CELL_X
      const mid = y + (cell.subtext ? ROW_HEIGHT / 2 - 8 : ROW_HEIGHT / 2)

      if (cell.alert) {
        const boxWidth = ctx.measureText(text).width + 16
        const boxX = right ? textX - boxWidth + 8 : textX - 8
        ctx.fillStyle = '#dc2626'
        ctx.beginPath()
        ctx.roundRect(boxX, mid - 12, boxWidth, 24, 4)
        ctx.fill()
        ctx.fillStyle = '#ffffff'
      } else {
        ctx.fillStyle = '#262626'
      }
      ctx.fillText(text, textX, mid)

      if (cell.subtext) {
        ctx.font = `12px ${font}`
        ctx.fillStyle = '#a3a3a3'
        ctx.fillText(truncate(ctx, cell.subtext, column.width - CELL_X * 2), textX, mid + 17)
      }
      cellX += column.width
    })

    ctx.strokeStyle = '#e5e5e5'
    ctx.beginPath()
    ctx.moveTo(PADDING, y + ROW_HEIGHT - 0.5)
    ctx.lineTo(PADDING + tableWidth, y + ROW_HEIGHT - 0.5)
    ctx.stroke()
    y += ROW_HEIGHT
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
