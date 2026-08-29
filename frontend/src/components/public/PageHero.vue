<script setup lang="ts">
interface Props {
  title: string
  subtitle?: string
}

defineProps<Props>()
</script>

<template>
  <div class="bg-gradient-to-br from-primary-800 to-primary-900 text-white">
    <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
      <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">{{ title }}</h1>
      <p v-if="subtitle" class="mt-3 max-w-2xl text-primary-100">{{ subtitle }}</p>
    </div>
  </div>
</template>
