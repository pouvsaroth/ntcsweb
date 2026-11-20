import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { setChildHeader } from '@/services/http'
import { parentPortalService, type MyChild } from '@/services/parentPortal'

const KEY = 'ntcsweb.parent_child'

/**
 * Parent portal: the parent's children and the one being looked at. The
 * student pages (scores, attendance...) send it as X-Child while a parent
 * page is open (router: meta.parentPage), so a teacher who is also a parent
 * keeps their own pages untouched.
 */
export const useParentChildStore = defineStore('parentChild', () => {
  const children = ref<MyChild[]>([])
  const loaded = ref(false)
  const selectedId = ref<number | null>(readSaved())
  const onParentPage = ref(false)

  const selected = computed(() => children.value.find((c) => c.id === selectedId.value) ?? children.value[0] ?? null)

  function readSaved(): number | null {
    try {
      const v = Number(localStorage.getItem(KEY))
      return Number.isFinite(v) && v > 0 ? v : null
    } catch {
      return null
    }
  }

  function apply(): void {
    setChildHeader(onParentPage.value ? (selected.value?.id ?? null) : null)
  }

  let loadedFor: number | null = null
  /** Once per signed-in user (another login on this browser gets its own children). */
  async function load(userId: number | null): Promise<void> {
    if (loaded.value && loadedFor === userId) return
    children.value = await parentPortalService.children().catch(() => [])
    loaded.value = true
    loadedFor = userId
    apply()
  }

  function select(id: number): void {
    selectedId.value = id
    try {
      localStorage.setItem(KEY, String(id))
    } catch {
      /* private mode: kept for this visit only */
    }
    apply()
  }

  function setOnParentPage(on: boolean): void {
    onParentPage.value = on
    apply()
  }

  function reset(): void {
    children.value = []
    loaded.value = false
    setChildHeader(null)
  }

  return { children, loaded, selected, selectedId, load, select, setOnParentPage, reset }
})
