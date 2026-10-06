<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import type { ChartNode } from '@/services/organizationChart'

/**
 * One row of an Organization Management tree, plus (when expanded) its
 * children — recursive, so the same component draws Branch > Department >
 * Team > staff and the manager > report chain. A person links to their
 * staff record.
 */
const props = withDefaults(defineProps<{ node: ChartNode; depth?: number; expandedByDefault?: boolean }>(), {
  depth: 0,
  expandedByDefault: true,
})

const { t } = useI18n()

const open = ref(props.expandedByDefault)
const hasChildren = computed(() => props.node.children.length > 0)
const staffCount = computed(() => countStaff(props.node))

function countStaff(node: ChartNode): number {
  return node.children.reduce((total, child) => total + (child.kind === 'staff' ? 1 : 0) + countStaff(child), 0)
}

const kindClass: Record<ChartNode['kind'], string> = {
  branch: 'bg-primary-100 text-primary-800',
  department: 'bg-secondary-100 text-secondary-800',
  team: 'bg-amber-100 text-amber-800',
  group: 'bg-neutral-100 text-neutral-600',
  staff: '',
}

function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]!.toUpperCase())
    .join('')
}
</script>

<template>
  <li>
    <div class="flex items-center gap-2 rounded-lg py-1.5 pr-2 hover:bg-neutral-50" :style="{ paddingLeft: `${depth * 1.25 + 0.25}rem` }">
      <button
        type="button"
        class="flex h-5 w-5 shrink-0 items-center justify-center rounded text-neutral-400 hover:text-neutral-700"
        :class="hasChildren ? '' : 'invisible'"
        :aria-expanded="open"
        :aria-label="open ? t('admin.organization.collapse') : t('admin.organization.expand')"
        @click="open = !open"
      >
        <svg class="h-3.5 w-3.5 transition-transform" :class="open ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
        </svg>
      </button>

      <template v-if="node.kind === 'staff' && node.staff">
        <div
          class="flex h-7 w-7 shrink-0 items-center justify-center overflow-hidden rounded-full text-[11px] font-semibold text-white"
          :style="{ backgroundColor: node.staff.photo_url ? undefined : (node.staff.profile_color ?? '#94A3B8') }"
        >
          <img v-if="node.staff.photo_url" :src="node.staff.photo_url" alt="" class="h-full w-full object-cover" />
          <template v-else>{{ initials(node.staff.full_name) }}</template>
        </div>
        <RouterLink :to="`/admin/staff/${node.staff.id}/edit`" class="min-w-0 truncate text-sm font-medium text-neutral-800 hover:text-primary-700">
          {{ node.label }}
        </RouterLink>
        <span v-if="node.sublabel" class="min-w-0 truncate text-xs text-neutral-500">{{ node.sublabel }}</span>
        <span v-if="hasChildren" class="ml-auto shrink-0 text-xs text-neutral-400">{{ t('admin.organization.directReports', node.children.length) }}</span>
      </template>

      <template v-else>
        <span class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide" :class="kindClass[node.kind]">
          {{ t(`admin.organization.kindLabels.${node.kind}`) }}
        </span>
        <span class="min-w-0 truncate text-sm font-semibold text-neutral-800">{{ node.label }}</span>
        <span v-if="node.sublabel" class="shrink-0 text-xs text-neutral-400">{{ node.sublabel }}</span>
        <span class="ml-auto shrink-0 text-xs text-neutral-400">{{ t('admin.organization.staffCount', staffCount) }}</span>
      </template>
    </div>

    <ul v-if="open && hasChildren">
      <OrgChartNode v-for="child in node.children" :key="child.key" :node="child" :depth="depth + 1" :expanded-by-default="expandedByDefault" />
    </ul>
  </li>
</template>
