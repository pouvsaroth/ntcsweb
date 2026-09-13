<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { tenantDirectoryService, type TenantOption } from '@/services/tenantDirectory'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const auth = useAuthStore()

const tenants = ref<TenantOption[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

async function load() {
  loading.value = true
  loadError.value = null

  try {
    tenants.value = await tenantDirectoryService.list()
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.tenants.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.tenants.title') }}</h1>
    <p class="mt-1 text-sm text-neutral-500">{{ t('admin.tenants.pageSubtitle') }}</p>

    <BaseSpinner v-if="loading" class="mx-auto mt-8" />
    <BaseAlert v-else-if="loadError" variant="danger" class="mt-6">{{ loadError }}</BaseAlert>

    <div v-else class="mt-6 divide-y divide-neutral-100 rounded-[--radius-card] border border-neutral-200 bg-white">
      <p v-if="tenants.length === 0" class="p-4 text-sm text-neutral-500">{{ t('admin.tenants.emptyMessage') }}</p>

      <div v-for="tenant in tenants" :key="tenant.id" class="flex items-center justify-between gap-4 p-4">
        <div class="min-w-0">
          <p class="truncate font-medium text-neutral-800">{{ tenant.name }}</p>
          <p class="truncate text-xs text-neutral-500">{{ tenant.slug }}</p>
        </div>
        <BaseButton
          v-if="auth.actingTenant?.slug === tenant.slug"
          variant="outline"
          size="sm"
          disabled
        >
          {{ t('admin.tenants.currentlyActing') }}
        </BaseButton>
        <BaseButton v-else variant="outline" size="sm" @click="auth.enterTenant(tenant)">
          {{ t('admin.tenants.enter') }}
        </BaseButton>
      </div>
    </div>
  </div>
</template>
