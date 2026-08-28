<script setup lang="ts">
import { onMounted } from 'vue'

import PublicFooter from '@/components/layout/PublicFooter.vue'
import PublicHeader from '@/components/layout/PublicHeader.vue'
import { useSiteStore } from '@/stores/site'

const site = useSiteStore()
onMounted(() => site.load())
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <PublicHeader />
    <main class="flex-1">
      <RouterView />
    </main>
    <PublicFooter />
  </div>
</template>
