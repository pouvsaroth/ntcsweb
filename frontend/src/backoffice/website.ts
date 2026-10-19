import type { WebsiteImageKind } from './api'

/** The text fields each picture list has, besides the picture, order and status. */
export const imageFields: Record<WebsiteImageKind, readonly ('title' | 'subtitle' | 'link_url' | 'caption')[]> = {
  slides: ['title', 'subtitle', 'link_url'],
  gallery: ['caption'],
  promotions: ['title'],
}

/** The line a list shows for an item: its title, or its caption. */
export const imageLabelField: Record<WebsiteImageKind, 'title' | 'caption'> = {
  slides: 'title',
  gallery: 'caption',
  promotions: 'title',
}
