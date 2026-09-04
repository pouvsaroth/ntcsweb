<script setup lang="ts">
import { onMounted } from 'vue'

import PublicFooter from '@/components/layout/PublicFooter.vue'
import PublicHeader from '@/components/layout/PublicHeader.vue'
import MobileBottomNav from '@/components/public/MobileBottomNav.vue'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'

const site = useSiteStore()
const auth = useAuthStore()

onMounted(() => {
  site.load()
  // The router guard only resolves auth lazily for requiresAuth/guestOnly
  // routes (admin, login) — a plain public route never triggers it, so a
  // student staying on the public site after login (see Login.vue) would
  // otherwise never have their session checked here at all.
  if (!auth.initialized) auth.initialize()
})
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
