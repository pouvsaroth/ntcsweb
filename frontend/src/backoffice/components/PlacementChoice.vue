<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import type { PlacementMode } from '../api'

/** Shared / Dedicated / Separate server — three cards, each saying what it means. */
const model = defineModel<PlacementMode>({ required: true })
const props = defineProps<{ options?: PlacementMode[] }>()

const { t } = useI18n()
const all: PlacementMode[] = ['shared', 'dedicated', 'separate']
const choices = () => props.options ?? all
</script>

<template>
  <div class="grid gap-2 sm:grid-cols-3" role="radiogroup">
    <label
      v-for="mode in choices()"
      :key="mode"
      class="flex cursor-pointer flex-col gap-1 rounded-lg border p-3 text-sm"
      :class="model === mode ? 'border-primary-500 bg-primary-50 ring-1 ring-primary-500' : 'border-neutral-200 hover:border-neutral-300'"
    >
      <span class="flex items-center gap-2 font-medium text-neutral-900">
        <input v-model="model" type="radio" :value="mode" class="text-primary-600 focus:ring-primary-500" />
        {{ t(`bo.placement.${mode}`) }}
      </span>
      <span class="text-xs text-neutral-500">{{ t(`bo.placement.${mode}Hint`) }}</span>
    </label>
  </div>
</template>
