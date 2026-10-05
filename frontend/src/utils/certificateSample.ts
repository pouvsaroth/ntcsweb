/**
 * A placeholder certificate page — Examination → Certificate's "Print
 * Certificate" until the school's real certificate design arrives. One A4
 * portrait page (150 dpi) per student, drawn on a canvas so it prints
 * through printImages() exactly like the final design will: replace this
 * file's drawing with the real template and nothing else has to change.
 */
export interface CertificateSampleFields {
  /** Big "SAMPLE" heading text — already translated by the caller. */
  heading: string
  /** Small line under the heading saying this is a placeholder. */
  note: string
  /** Label/value pairs printed down the page, e.g. ["Full name", "Sok Dara"]. */
  lines: [label: string, value: string][]
}

// A4 at 150 dpi.
const WIDTH = 1240
const HEIGHT = 1754

export async function renderCertificateSample(fields: CertificateSampleFields): Promise<Blob> {
  const canvas = document.createElement('canvas')
  canvas.width = WIDTH
  canvas.height = HEIGHT
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new Error('Canvas is not available in this browser.')

  // The page's own font, so Khmer names render (same as tableImage.ts).
  const font = getComputedStyle(document.body).fontFamily || 'sans-serif'

  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, WIDTH, HEIGHT)

  ctx.strokeStyle = '#a3a3a3'
  ctx.lineWidth = 4
  ctx.setLineDash([24, 16])
  ctx.strokeRect(60, 60, WIDTH - 120, HEIGHT - 120)
  ctx.setLineDash([])

  ctx.textAlign = 'center'
  ctx.fillStyle = '#404040'
  ctx.font = `700 72px ${font}`
  ctx.fillText(fields.heading, WIDTH / 2, 300)
  ctx.fillStyle = '#737373'
  ctx.font = `28px ${font}`
  ctx.fillText(fields.note, WIDTH / 2, 360)

  let y = 600
  for (const [label, value] of fields.lines) {
    ctx.fillStyle = '#737373'
    ctx.font = `26px ${font}`
    ctx.fillText(label, WIDTH / 2, y)
    ctx.fillStyle = '#171717'
    ctx.font = `600 44px ${font}`
    ctx.fillText(value || '—', WIDTH / 2, y + 60, WIDTH - 240)
    y += 170
  }

  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'))
  if (!blob) throw new Error('Could not draw the certificate.')
  return blob
}
