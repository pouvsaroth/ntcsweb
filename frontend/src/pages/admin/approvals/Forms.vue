<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import AskForPermissionModal from '@/components/layout/AskForPermissionModal.vue'
import RequestFormModal from '@/components/admin/RequestFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { formCategoriesService, type FormCategory } from '@/services/formCategories'
import { formTemplatesService, type FormTemplate } from '@/services/formTemplates'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const categories = ref<FormCategory[]>([])
const activeCategoryId = ref<number | null>(null)
const templates = ref<FormTemplate[]>([])
const loadingCategories = ref(false)
const loadingTemplates = ref(false)
const error = ref<string | null>(null)

const requestModalOpen = ref(false)
const selectedTemplate = ref<FormTemplate | null>(null)
const leaveModalOpen = ref(false)

async function loadCategories() {
  loadingCategories.value = true
  error.value = null

  try {
    const result = await formCategoriesService.list()
    categories.value = result.data.filter((category) => category.is_active)
    activeCategoryId.value = categories.value[0]?.id ?? null
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.forms.loadFailed')
  } finally {
    loadingCategories.value = false
  }
}

async function loadTemplates(categoryId: number) {
  loadingTemplates.value = true
  error.value = null

  try {
    const result = await formTemplatesService.list({ categoryId })
    templates.value = result.data.filter((template) => template.is_active)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.forms.loadFailed')
  } finally {
    loadingTemplates.value = false
  }
}

function openRequest(template: FormTemplate) {
  selectedTemplate.value = template
  requestModalOpen.value = true
}

watch(activeCategoryId, (id) => {
  if (id !== null) void loadTemplates(id)
})

onMounted(() => loadCategories())
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.forms.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.forms.subtitle') }}</p>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-6 rounded-[--radius-card] border border-neutral-200 bg-white p-4">
      <p class="mb-3 text-sm font-medium text-neutral-700">{{ t('admin.forms.quickActions') }}</p>
      <button
        type="button"
        class="flex w-full max-w-xs flex-col items-start gap-2 rounded-lg border border-neutral-200 p-4 text-left transition-colors hover:border-primary-300 hover:bg-primary-50"
        @click="leaveModalOpen = true"
      >
        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-100 text-primary-700">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
        </span>
        <span class="font-medium text-neutral-900">{{ t('leaveRequest.title') }}</span>
      </button>
    </div>

    <div v-if="loadingCategories" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-else-if="categories.length">
      <div class="mb-4 flex gap-1 overflow-x-auto border-b border-neutral-200">
        <button
          v-for="category in categories"
          :key="category.id"
          type="button"
          class="shrink-0 border-b-2 px-4 py-2 text-sm font-medium transition-colors"
          :class="
            activeCategoryId === category.id
              ? 'border-primary-600 text-primary-700'
              : 'border-transparent text-neutral-500 hover:text-neutral-700'
          "
          @click="activeCategoryId = category.id"
        >
          {{ category.name }}
        </button>
      </div>

      <div v-if="loadingTemplates" class="flex justify-center py-10"><BaseSpinner /></div>

      <p v-else-if="templates.length === 0" class="py-10 text-center text-sm text-neutral-400">
        {{ t('admin.forms.emptyMessage') }}
      </p>

      <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div
          v-for="template in templates"
          :key="template.id"
          class="flex flex-col items-center gap-3 rounded-[--radius-card] border border-neutral-200 bg-white p-5 text-center"
        >
          <span class="flex h-12 w-12 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V4a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V20a2 2 0 01-2 2z" />
            </svg>
          </span>
          <div>
            <p class="text-xs font-medium text-neutral-500">{{ template.code }}</p>
            <p class="text-sm font-medium text-neutral-900">{{ template.name }}</p>
          </div>
          <button
            type="button"
            class="mt-1 inline-flex items-center gap-1 rounded-lg bg-primary-50 px-3 py-1.5 text-sm font-medium text-primary-700 hover:bg-primary-100"
            @click="openRequest(template)"
          >
            {{ t('admin.forms.request') }}
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
            </svg>
          </button>
        </div>
      </div>
    </template>

    <p v-else class="py-10 text-center text-sm text-neutral-400">{{ t('admin.forms.noCategoriesMessage') }}</p>

    <RequestFormModal v-model="requestModalOpen" :template="selectedTemplate" />
    <AskForPermissionModal v-model="leaveModalOpen" />
  </div>
</template>
