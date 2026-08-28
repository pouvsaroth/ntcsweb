<script setup lang="ts">
interface Props {
  hoverable?: boolean
  padded?: boolean
}

withDefaults(defineProps<Props>(), {
  hoverable: false,
  padded: true,
})
</script>

<template>
  <div
    class="rounded-[--radius-card] bg-white border border-neutral-200 shadow-[--shadow-card] transition-shadow"
    :class="[padded ? 'p-5' : '', hoverable ? 'hover:shadow-[--shadow-card-hover]' : '']"
  >
    <div v-if="$slots.header" class="mb-4 flex items-center justify-between">
      <slot name="header" />
    </div>
    <slot />
    <div v-if="$slots.footer" class="mt-4 border-t border-neutral-100 pt-4">
      <slot name="footer" />
    </div>
  </div>
</template>
