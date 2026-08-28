<script setup lang="ts">
interface Props {
  title?: string
  subtitle?: string
}

defineProps<Props>()
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
    <div v-if="title" class="mb-8 text-center">
      <h2 class="text-2xl font-bold text-neutral-900 sm:text-3xl">{{ title }}</h2>
      <p v-if="subtitle" class="mt-2 text-neutral-500">{{ subtitle }}</p>
    </div>
    <slot />
  </section>
</template>
