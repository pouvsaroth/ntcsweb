<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import AdminHeader from '@/components/layout/AdminHeader.vue'
import AdminSidebar from '@/components/layout/AdminSidebar.vue'
import MobileBottomNav from '@/components/layout/MobileBottomNav.vue'
import MonthlyPaymentAlertModal from '@/components/admin/MonthlyPaymentAlertModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import { useAdminUiStore } from '@/stores/adminUi'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'

const sidebarOpen = ref(false)
const adminUi = useAdminUiStore()
const auth = useAuthStore()
const site = useSiteStore()
const { t } = useI18n()

// A student landing straight here (e.g. a hard refresh on /admin/my-scores,
// not by clicking through from the public site first) would otherwise never
// trigger this — AdminSidebar's "My Profile" group needs it loaded to know
// whether School Regulation/Attendance Policy have actually been uploaded.
// A no-op if PublicLayout/AuthLayout already loaded it (see site.ts's
// `loaded` guard).
onMounted(() => site.load())
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
      <!-- A Super Admin browsing one school's admin data (see stores/auth.ts's
           enterTenant) — this must stay visible on every tenant-scoped page so
           it's never ambiguous whose data is on screen, with an always-reachable
           way back out. -->
      <div
        v-if="auth.actingTenant"
        class="flex flex-wrap items-center justify-between gap-2 bg-amber-500 px-4 py-2 text-sm font-medium text-amber-950 sm:px-6"
      >
        <span>{{ t('admin.tenants.actingAsBanner', { name: auth.actingTenant.name }) }}</span>
        <button type="button" class="rounded-lg border border-amber-950/30 px-3 py-1 hover:bg-amber-400" @click="auth.exitTenant()">
          {{ t('admin.tenants.exit') }}
        </button>
      </div>
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

    <MobileBottomNav />
    <MonthlyPaymentAlertModal v-if="auth.hasRole('student')" />
    <ConfirmDialog />
  </div>
</template>
