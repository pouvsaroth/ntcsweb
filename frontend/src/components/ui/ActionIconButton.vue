<script setup lang="ts">
/** A generic icon-only row action, click-based (see EditIconButton for the route-based equivalent) — the caller supplies the icon via the default slot and the tooltip via `title`. */
withDefaults(defineProps<{ title: string; variant?: 'default' | 'danger' }>(), { variant: 'default' })
defineEmits<{ click: [] }>()
</script>

<template>
  <button
    type="button"
    :aria-label="title"
    :title="title"
    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
    :class="
      variant === 'danger'
        ? 'text-danger-600 hover:bg-danger-50 hover:text-red-700'
        : 'text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700'
    "
    @click="$emit('click')"
  >
    <slot />
  </button>
</template>
