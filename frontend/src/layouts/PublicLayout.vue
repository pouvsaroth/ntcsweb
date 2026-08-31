<script setup lang="ts">
import { onMounted } from 'vue'

import PublicFooter from '@/components/layout/PublicFooter.vue'
import PublicHeader from '@/components/layout/PublicHeader.vue'
import MobileBottomNav from '@/components/public/MobileBottomNav.vue'
import { useSiteStore } from '@/stores/site'

const site = useSiteStore()
onMounted(() => site.load())
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <PublicHeader />
    <!-- pb-16 clears the fixed MobileBottomNav (mobile/tablet only, see
         its own lg:hidden) so the last section on a page is never hidden
         behind it. -->
    <main class="flex-1 pb-16 lg:pb-0">
      <RouterView />
    </main>
    <PublicFooter />
    <MobileBottomNav />
  </div>
</template>
