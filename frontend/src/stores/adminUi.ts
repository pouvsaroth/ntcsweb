import { ref } from 'vue'
import { defineStore } from 'pinia'

const STORAGE_KEY = 'ntcsweb.admin.sidebar.collapsed'

function loadStored(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

/**
 * Whether the admin sidebar is collapsed to a narrow icon rail (desktop
 * only — mobile keeps its own separate open/closed drawer state, which is
 * transient and lives locally in AdminLayout instead).
 *
 * Lives in a store, not a prop passed down from AdminLayout, because
 * BasePagination's `sticky` bar needs to know the *current* sidebar width
 * too (its `left` offset has to match, or it either overlaps the sidebar or
 * leaves an empty gap) — and it sits several component layers below
 * AdminLayout, on every admin list page. A store lets both read the same
 * reactive value directly instead of prop-drilling it through every page.
 */
export const useAdminUiStore = defineStore('adminUi', () => {
  const sidebarCollapsed = ref(loadStored())

  function toggleSidebarCollapsed(): void {
    sidebarCollapsed.value = !sidebarCollapsed.value
    try {
      localStorage.setItem(STORAGE_KEY, sidebarCollapsed.value ? '1' : '0')
    } catch {
      // Best-effort — a private window or full storage just means the
      // choice doesn't survive a reload, not a broken sidebar.
    }
  }

  return { sidebarCollapsed, toggleSidebarCollapsed }
})
