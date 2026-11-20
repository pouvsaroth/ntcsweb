<script setup lang="ts">
/** Parent portal: whose page this is. Shown above every parent page; one tap switches child. */
import { useI18n } from 'vue-i18n'

import { useParentChildStore } from '@/stores/parentChild'

const { t } = useI18n()
const store = useParentChildStore()
</script>

<template>
  <div v-if="store.children.length" class="mb-4 flex flex-wrap items-center gap-2 rounded-[--radius-card] border border-neutral-200 bg-white p-2 shadow-[--shadow-card]">
    <span class="px-1 text-xs font-medium text-neutral-500">{{ t('parentPortal.viewing') }}</span>
    <button
      v-for="c in store.children"
      :key="c.id"
      type="button"
      class="flex items-center gap-2 rounded-full border py-1 pr-3 pl-1 text-sm transition"
      :class="store.selected?.id === c.id ? 'border-primary-500 bg-primary-50 font-semibold text-primary-800' : 'border-neutral-200 text-neutral-700 hover:bg-neutral-50'"
      :aria-pressed="store.selected?.id === c.id"
      @click="store.select(c.id)"
    >
      <img v-if="c.photo_url" :src="c.photo_url" alt="" class="h-7 w-7 rounded-full object-cover" />
      <span v-else class="flex h-7 w-7 items-center justify-center rounded-full bg-neutral-200 text-xs font-semibold text-neutral-600">{{ c.name.charAt(0) }}</span>
      {{ c.name }}
    </button>
  </div>
  <p v-else-if="store.loaded" class="mb-4 rounded-[--radius-card] border border-dashed border-neutral-300 p-4 text-sm text-neutral-500">{{ t('parentPortal.noChildren') }}</p>
</template>
