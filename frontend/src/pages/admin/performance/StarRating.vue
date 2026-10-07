<script setup lang="ts">
import { useI18n } from 'vue-i18n'

/** A 1–5 rating — clickable stars, or just shown when `readonly`. Clicking the chosen star again clears it. */
const model = defineModel<number | null>({ default: null })
defineProps<{ readonly?: boolean; small?: boolean }>()

const { t } = useI18n()

function pick(star: number) {
  model.value = model.value === star ? null : star
}
</script>

<template>
  <div class="inline-flex items-center gap-0.5" role="radiogroup" :aria-label="t('admin.performance.rating.label')">
    <template v-for="star in 5" :key="star">
      <button
        v-if="!readonly"
        type="button"
        role="radio"
        :aria-checked="model === star"
        :aria-label="t(`admin.performance.rating.${star}`)"
        :title="t(`admin.performance.rating.${star}`)"
        class="leading-none transition-colors"
        :class="[small ? 'text-base' : 'text-xl', model !== null && star <= model ? 'text-amber-400' : 'text-neutral-300 hover:text-amber-200']"
        @click="pick(star)"
      >
        ★
      </button>
      <span v-else class="leading-none" :class="[small ? 'text-sm' : 'text-lg', model !== null && star <= model ? 'text-amber-400' : 'text-neutral-200']">★</span>
    </template>
    <span v-if="readonly && model === null" class="ml-1 text-xs text-neutral-400">—</span>
  </div>
</template>
