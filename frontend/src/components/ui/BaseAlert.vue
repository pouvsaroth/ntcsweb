<script setup lang="ts">
interface Props {
  variant?: 'info' | 'success' | 'warning' | 'danger'
  title?: string
}

withDefaults(defineProps<Props>(), {
  variant: 'info',
  title: undefined,
})

const styles = {
  info: 'bg-primary-50 text-primary-800 border-primary-200',
  success: 'bg-green-50 text-green-800 border-green-200',
  warning: 'bg-amber-50 text-amber-800 border-amber-200',
  danger: 'bg-red-50 text-red-800 border-red-200',
} as const
</script>

<template>
  <div role="alert" class="rounded-lg border px-4 py-3 text-sm" :class="styles[variant]">
    <p v-if="title" class="mb-1 font-semibold">{{ title }}</p>
    <slot />
  </div>
</template>
