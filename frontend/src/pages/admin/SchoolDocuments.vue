<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { schoolDocumentsService, type SchoolDocuments } from '@/services/schoolDocuments'
import { ApiRequestError } from '@/types/api'

/**
 * Uploads the two fixed public documents (School Regulation, Student
 * Attendance Policy) the public site's "Document and Form" menu links to.
 * See SchoolDocumentsContent on the backend — exactly two fixed files, not
 * a document library.
 */
const { t } = useI18n()

const regulationFile = ref<File | null>(null)
const attendancePolicyFile = ref<File | null>(null)
const current = ref<SchoolDocuments | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const saved = ref(false)
const saving = ref(false)

async function load() {
  loading.value = true
  loadError.value = null

  try {
    current.value = await schoolDocumentsService.get()
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.schoolDocuments.loadFailed')
  } finally {
    loading.value = false
  }
}

function onRegulationChange(event: Event) {
  regulationFile.value = (event.target as HTMLInputElement).files?.[0] ?? null
}

function onAttendancePolicyChange(event: Event) {
  attendancePolicyFile.value = (event.target as HTMLInputElement).files?.[0] ?? null
}

async function save() {
  saving.value = true
  saveError.value = null
  errors.value = {}
  saved.value = false

  try {
    current.value = await schoolDocumentsService.save({
      school_regulation: regulationFile.value ?? undefined,
      student_attendance_policy: attendancePolicyFile.value ?? undefined,
    })
    regulationFile.value = null
    attendancePolicyFile.value = null
    saved.value = true
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      saveError.value = error instanceof ApiRequestError ? error.message : t('admin.schoolDocuments.saveFailed')
    }
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-2xl">
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.schoolDocuments.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.schoolDocuments.subtitle') }}</p>
    </div>

    <BaseAlert v-if="loadError" variant="danger" class="mb-4">{{ loadError }}</BaseAlert>

    <form v-else-if="!loading" class="space-y-6" @submit.prevent="save">
      <section class="rounded-lg border border-neutral-200 p-4">
        <h2 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.schoolDocuments.regulationSection') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('admin.schoolDocuments.fileHint') }}</p>

        <div class="flex items-center gap-4">
          <a
            v-if="current?.school_regulation_url"
            :href="current.school_regulation_url"
            target="_blank"
            rel="noopener"
            class="text-sm font-medium text-primary-700 hover:underline"
          >
            {{ t('admin.schoolDocuments.currentFile') }}
          </a>
          <span v-else class="text-sm text-neutral-400">{{ t('admin.schoolDocuments.noFile') }}</span>
        </div>

        <label class="mt-3 inline-block cursor-pointer">
          <span class="inline-flex items-center rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
            {{ t('admin.schoolDocuments.chooseFile') }}
          </span>
          <input type="file" accept=".pdf,.doc,.docx" class="hidden" @change="onRegulationChange" />
        </label>
        <span v-if="regulationFile" class="ml-3 text-sm text-neutral-600">{{ regulationFile.name }}</span>
        <p v-if="errors.school_regulation?.[0]" class="mt-2 text-sm text-danger-600">{{ errors.school_regulation[0] }}</p>
      </section>

      <section class="rounded-lg border border-neutral-200 p-4">
        <h2 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.schoolDocuments.attendancePolicySection') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('admin.schoolDocuments.fileHint') }}</p>

        <div class="flex items-center gap-4">
          <a
            v-if="current?.student_attendance_policy_url"
            :href="current.student_attendance_policy_url"
            target="_blank"
            rel="noopener"
            class="text-sm font-medium text-primary-700 hover:underline"
          >
            {{ t('admin.schoolDocuments.currentFile') }}
          </a>
          <span v-else class="text-sm text-neutral-400">{{ t('admin.schoolDocuments.noFile') }}</span>
        </div>

        <label class="mt-3 inline-block cursor-pointer">
          <span class="inline-flex items-center rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
            {{ t('admin.schoolDocuments.chooseFile') }}
          </span>
          <input type="file" accept=".pdf,.doc,.docx" class="hidden" @change="onAttendancePolicyChange" />
        </label>
        <span v-if="attendancePolicyFile" class="ml-3 text-sm text-neutral-600">{{ attendancePolicyFile.name }}</span>
        <p v-if="errors.student_attendance_policy?.[0]" class="mt-2 text-sm text-danger-600">{{ errors.student_attendance_policy[0] }}</p>
      </section>

      <div class="flex flex-wrap items-center gap-3">
        <BaseButton type="submit" :loading="saving">{{ t('common.save') }}</BaseButton>
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <BaseAlert v-if="saved" variant="success">{{ t('admin.schoolDocuments.saveSuccess') }}</BaseAlert>
      </div>
    </form>
  </div>
</template>
