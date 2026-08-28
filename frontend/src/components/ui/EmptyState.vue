<script setup lang="ts">
interface Props {
  title: string
  message?: string
}

defineProps<Props>()
</script>

<template>
  <div class="flex flex-col items-center justify-center rounded-[--radius-card] border border-dashed border-neutral-300 bg-neutral-50 px-6 py-16 text-center">
    <svg class="mb-3 h-10 w-10 text-neutral-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 13h6m-6 4h6m2 5H7a2 2 0 01-2-2V4a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V20a2 2 0 01-2 2z" />
    </svg>
    <p class="font-medium text-neutral-700">{{ title }}</p>
    <p v-if="message" class="mt-1 max-w-sm text-sm text-neutral-500">{{ message }}</p>
    <div v-if="$slots.default" class="mt-4">
      <slot />
    </div>
  </div>
</template>
