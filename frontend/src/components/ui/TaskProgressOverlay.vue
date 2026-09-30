<script setup lang="ts">
/**
 * A full-screen progress bar for a task the user has to wait on (see
 * useTaskProgress) — e.g. "Save and Print" creating the invoice image.
 * Blocks clicks underneath so the form can't be submitted twice.
 */
defineProps<{ percent: number | null; label: string }>()
</script>

<template>
  <Teleport to="body">
    <Transition enter-active-class="transition-opacity duration-150" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0">
      <div v-if="percent !== null" class="fixed inset-0 z-[60] flex items-center justify-center bg-neutral-900/40 px-4">
        <div class="w-full max-w-sm rounded-[--radius-card] bg-white p-5 shadow-lg">
          <div class="mb-3 flex items-center justify-between gap-3 text-sm">
            <span class="font-medium text-neutral-800">{{ label }}</span>
            <span class="tabular-nums text-neutral-500">{{ Math.round(percent) }}%</span>
          </div>
          <div
            class="h-2.5 w-full overflow-hidden rounded-full bg-neutral-200"
            role="progressbar"
            :aria-valuenow="Math.round(percent)"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="label"
          >
            <div class="h-full rounded-full bg-primary-600 transition-[width] duration-150 ease-out" :style="{ width: `${percent}%` }" />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
