<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import GradebookPicker from '@/components/admin/GradebookPicker.vue'
import TranscriptView from '@/components/admin/TranscriptView.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { studentResultsService, type GradebookPick, type RosterStudent, type Transcript } from '@/services/gradebook'
import { ApiRequestError } from '@/types/api'

/**
 * Examination > Academic Transcript (docs/academics/gradebook.md, 7d): pick a
 * class and a student; their results year by year. The PDF has approved
 * results only; on screen, results still waiting are marked with *.
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const students = ref<RosterStudent[]>([])
const studentId = ref('')
const approvedOnly = ref(false)
const transcript = ref<Transcript | null>(null)
const loading = ref(false)
const downloading = ref(false)
const error = ref<string | null>(null)

const studentOptions = computed(() =>
  students.value.length ? students.value.map((s) => ({ value: String(s.id), label: `${s.name} · ${s.code}` })) : [{ value: '', label: t('gradebook.entry.noStudents') }],
)

async function onPick(p: GradebookPick) {
  transcript.value = null
  students.value = p.classId ? await studentResultsService.students(p.classId).catch(() => []) : []
  const wanted = typeof route.query.student === 'string' ? route.query.student : ''
  studentId.value = students.value.some((s) => String(s.id) === wanted) ? wanted : String(students.value[0]?.id ?? '')
  await load()
}

async function load() {
  if (!studentId.value) return
  void router.replace({ query: { ...route.query, student: studentId.value } })
  loading.value = true
  error.value = null
  try {
    transcript.value = await studentResultsService.transcript(Number(studentId.value), approvedOnly.value)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.loadFailed')
  } finally {
    loading.value = false
  }
}

watch(approvedOnly, () => void load())

async function download() {
  if (!transcript.value?.student) return
  downloading.value = true
  try {
    await studentResultsService.downloadTranscript(transcript.value.student.id, `transcript-${transcript.value.student.code}.pdf`)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.loadFailed')
  } finally {
    downloading.value = false
  }
}
</script>

<template>
  <div>
    <div class="mb-4 grid gap-2 sm:grid-cols-[2fr_1fr] sm:items-end">
      <GradebookPicker no-term @change="onPick" />
      <BaseSelect v-model="studentId" :options="studentOptions" :label="t('gradebook.entry.student')" @update:model-value="load" />
    </div>

    <div v-if="transcript?.student" class="mb-4 flex flex-wrap items-center gap-3">
      <div class="min-w-0">
        <p class="font-semibold text-neutral-900">{{ transcript.student.name }}<span v-if="transcript.student.english_name" class="font-normal text-neutral-500"> · {{ transcript.student.english_name }}</span></p>
        <p class="text-xs text-neutral-500">{{ transcript.student.code }}</p>
      </div>
      <label class="flex items-center gap-2 text-sm text-neutral-700 sm:ml-auto"><input v-model="approvedOnly" type="checkbox" /> {{ t('gradebook.transcript.approvedOnly') }}</label>
      <BaseButton variant="outline" :loading="downloading" @click="download">{{ t('gradebook.transcript.pdf') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <TranscriptView v-else-if="transcript" :transcript="transcript" />
  </div>
</template>
