<script setup lang="ts">
import { ref } from 'vue'

import AdminHeader from '@/components/layout/AdminHeader.vue'
import AdminSidebar from '@/components/layout/AdminSidebar.vue'

const sidebarOpen = ref(false)
</script>

<template>
  <div class="min-h-screen bg-neutral-50 lg:flex">
    <AdminSidebar :open="sidebarOpen" @close="sidebarOpen = false" />

    <div class="flex min-h-screen flex-1 flex-col lg:pl-0">
      <AdminHeader @toggle-sidebar="sidebarOpen = !sidebarOpen" />
      <main class="flex-1 p-4 sm:p-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>
