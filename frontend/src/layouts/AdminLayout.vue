<script setup lang="ts">
import { ref } from 'vue'

import AdminHeader from '@/components/layout/AdminHeader.vue'
import AdminSidebar from '@/components/layout/AdminSidebar.vue'
import { useAdminUiStore } from '@/stores/adminUi'

const sidebarOpen = ref(false)
const adminUi = useAdminUiStore()
</script>

<template>
  <div class="min-h-screen bg-neutral-50">
    <!-- Fixed at every breakpoint (see AdminSidebar) — it must never scroll
         away with the page, so content needs to clear its width instead of
         sharing flex space with it. Its width toggles between lg:w-64 and
         lg:w-16 (see AdminSidebar) — this padding has to track that exactly,
         or content either overlaps it or leaves a gap. -->
    <AdminSidebar :open="sidebarOpen" @close="sidebarOpen = false" />

    <div class="flex min-h-screen flex-col" :class="adminUi.sidebarCollapsed ? 'lg:pl-16' : 'lg:pl-64'">
      <AdminHeader @toggle-sidebar="sidebarOpen = !sidebarOpen" />
      <!-- Extra bottom padding: a page with sticky pagination (BasePagination's
           `sticky` prop, or Students.vue's own bar) is `fixed`, so it no
           longer reserves its own space in normal flow — without this, it
           would sit on top of the last row instead of below it. Harmless on
           pages with no pagination, just a little empty space at the end. -->
      <main class="flex-1 p-4 pb-20 sm:p-6 sm:pb-20">
        <RouterView />
      </main>
    </div>
  </div>
</template>
