/**
 * Help & Support text (docs/backoffice/stage-13-help-support.md).
 *
 * pickText(): NewTech writes in English and Khmer; a Khmer screen shows
 * Khmer, every other language English, each falling back to the other.
 *
 * helpBlocks(): an article body is plain text with three light marks —
 * "# " starts a heading, "- " a list item, a blank line a new paragraph —
 * turned into blocks the page renders as text (never as HTML).
 */
export function pickText(locale: string, en: string | null | undefined, km: string | null | undefined): string {
  const first = locale === 'km' ? km : en
  const second = locale === 'km' ? en : km
  return (first ?? '').trim() || (second ?? '').trim()
}

export type HelpBlock = { type: 'h'; text: string } | { type: 'ul'; items: string[] } | { type: 'p'; text: string }

export function helpBlocks(body: string): HelpBlock[] {
  const blocks: HelpBlock[] = []
  let para: string[] = []
  const flush = () => {
    if (para.length) blocks.push({ type: 'p', text: para.join('\n') })
    para = []
  }
  for (const raw of body.replace(/\r\n/g, '\n').split('\n')) {
    const line = raw.trimEnd()
    if (line.trim() === '') {
      flush()
    } else if (/^#{1,3}\s+/.test(line)) {
      flush()
      blocks.push({ type: 'h', text: line.replace(/^#{1,3}\s+/, '') })
    } else if (/^\s*[-*•]\s+/.test(line)) {
      flush()
      const item = line.replace(/^\s*[-*•]\s+/, '')
      const last = blocks[blocks.length - 1]
      if (last?.type === 'ul') last.items.push(item)
      else blocks.push({ type: 'ul', items: [item] })
    } else {
      para.push(line)
    }
  }
  flush()
  return blocks
}

/** A Telegram contact as typed (@name, t.me/name or a full link) → its link. */
export function telegramLink(value: string): string {
  const v = value.trim()
  if (/^https?:\/\//i.test(v)) return v
  if (/^t\.me\//i.test(v)) return `https://${v}`
  return `https://t.me/${v.replace(/^@/, '')}`
}
