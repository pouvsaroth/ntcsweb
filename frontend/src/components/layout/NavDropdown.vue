<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import type { NavItem } from '@/router/publicNav'

/**
 * A single-level header dropdown grouping several NavItems under one label
 * — see publicNav.ts's `programNav` for the first use (folding Programs/
 * Day and Time Study/Video Lesson/Photos together so the header doesn't run
 * out of room). `mobile` renders an always-expanded inline section instead
 * of a click-to-open dropdown, same convention as DocumentsAndFormMenu.vue.
 */
withDefaults(defineProps<{ title: string; items: NavItem[]; mobile?: boolean }>(), { mobile: false })

const emit = defineEmits<{ navigate: [] }>()

const { t } = useI18n()

const open = ref(false)
</script>

<template>
  <div v-if="mobile" class="border-t border-neutral-100 pt-2">
    <p class="px-3 pt-1 text-xs font-semibold uppercase tracking-wide text-neutral-400">{{ title }}</p>
    <RouterLink
      v-for="item in items"
      :key="item.to"
      :to="item.to"
      class="block rounded-lg px-3 py-2.5 text-sm font-medium text-neutral-600 hover:bg-neutral-100"
      active-class="text-primary-800 bg-primary-50"
      @click="emit('navigate')"
    >
      {{ t(item.labelKey) }}
    </RouterLink>
  </div>

  <div v-else class="relative">
    <button
      type="button"
      class="flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900"
      :aria-expanded="open"
      @click="open = !open"
    >
      {{ title }}
      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
      </svg>
    </button>

    <div v-if="open" class="fixed inset-0 z-40" @click="open = false" />

    <Transition enter-active-class="transition ease-out duration-100" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100">
      <div v-if="open" class="absolute left-0 z-50 mt-2 w-56 rounded-lg border border-neutral-200 bg-white py-1 shadow-lg">
        <RouterLink
          v-for="item in items"
          :key="item.to"
          :to="item.to"
          class="block px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50"
          active-class="text-primary-800 bg-primary-50"
          @click="open = false"
        >
          {{ t(item.labelKey) }}
        </RouterLink>
      </div>
    </Transition>
  </div>
</template>
