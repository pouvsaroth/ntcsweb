<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'

import { adminNav } from '@/router/adminNav'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()

defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: [] }>()

const auth = useAuthStore()
const route = useRoute()

const visibleGroups = computed(() =>
  adminNav
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => {
        if (item.superAdminOnly && !auth.isSuperAdmin) return false
        if (item.permission && !auth.can(item.permission)) return false
        return true
      }),
    }))
    .filter((group) => group.items.length > 0),
)

const STORAGE_KEY = 'ntcsweb.admin.sidebar.expanded'

function loadStoredExpanded(): string[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    return raw ? (JSON.parse(raw) as string[]) : []
  } catch {
    return []
  }
}

function groupContainingCurrentRoute(): string | undefined {
  return adminNav.find((group) => group.items.some((item) => item.to === route.path))?.labelKey
}

// A returning visitor's manual choices win; a first-time visitor instead
// gets the group containing wherever they landed pre-expanded, so the
// current page is never hidden inside a collapsed section on first load.
const stored = loadStoredExpanded()
const expanded = reactive<Record<string, boolean>>(
  Object.fromEntries(
    adminNav.map((group) => [
      group.labelKey,
      stored.length > 0 ? stored.includes(group.labelKey) : group.labelKey === groupContainingCurrentRoute(),
    ]),
  ),
)

function persist(): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(Object.keys(expanded).filter((key) => expanded[key])))
  } catch {
    // Best-effort — a private window or full storage just means the choice
    // doesn't survive a reload, not a broken sidebar.
  }
}

function toggleGroup(labelKey: string): void {
  expanded[labelKey] = !expanded[labelKey]
  persist()
}

// Navigating to a page (e.g. via a dashboard shortcut, not the sidebar
// itself) should reveal its group without collapsing whatever else the user
// already had open.
watch(
  () => route.path,
  () => {
    const key = groupContainingCurrentRoute()
    if (key && !expanded[key]) {
      expanded[key] = true
      persist()
    }
  },
)
</script>

<template>
  <!-- Mobile overlay -->
  <div v-if="open" class="fixed inset-0 z-30 bg-neutral-900/50 lg:hidden" @click="emit('close')" />

  <aside
    class="fixed inset-y-0 left-0 z-40 w-64 transform overflow-y-auto border-r border-neutral-200 bg-white transition-transform lg:static lg:translate-x-0"
    :class="open ? 'translate-x-0' : '-translate-x-full'"
  >
    <div class="flex h-16 items-center gap-2 border-b border-neutral-200 px-5">
      <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-600 text-sm font-bold text-white">
        N
      </span>
      <span class="font-bold text-neutral-900">NTCSWEB Admin</span>
    </div>

    <nav class="space-y-1 px-3 py-5">
      <div v-for="group in visibleGroups" :key="group.labelKey" class="border-b border-neutral-100 pb-1 last:border-0">
        <button
          type="button"
          class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-xs font-semibold uppercase tracking-wide text-neutral-400 hover:bg-neutral-50 hover:text-neutral-600"
          :aria-expanded="expanded[group.labelKey]"
          @click="toggleGroup(group.labelKey)"
        >
          {{ t(group.labelKey) }}
          <svg
            class="h-3.5 w-3.5 shrink-0 transition-transform duration-200"
            :class="expanded[group.labelKey] ? 'rotate-180' : ''"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2.5"
          >
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
          </svg>
        </button>

        <div
          class="grid transition-[grid-template-rows] duration-200 ease-in-out"
          :class="expanded[group.labelKey] ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
        >
          <div class="overflow-hidden">
            <div class="mt-1 space-y-0.5 pb-2">
              <RouterLink
                v-for="item in group.items"
                :key="item.to"
                :to="item.to"
                class="block rounded-lg px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900"
                active-class="bg-primary-50 text-primary-700"
                @click="emit('close')"
              >
                {{ t(item.labelKey) }}
              </RouterLink>
            </div>
          </div>
        </div>
      </div>
    </nav>
  </aside>
</template>
