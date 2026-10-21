<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { lessonPlansService, type LessonOptions, type Progress } from '@/services/lessonPlans'
import { ApiRequestError } from '@/types/api'

/**
 * Lesson plans > Curriculum progress: per subject of a class, its curriculum
 * lessons (School setup > Curriculum) taught and remaining — a lesson counts
 * as taught when the teaching log marks it done.
 */
const { t } = useI18n()

const options = ref<LessonOptions>({ me: null, sees_all: false, classes: [], subjects: [], teachers: [] })
const classId = ref('')
const data = ref<Progress | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const openSubject = ref<number | null>(null)

const classOptions = computed(() => options.value.classes.map((c) => ({ value: String(c.id), label: c.grade_level ? `${c.name} · ${c.grade_level}` : c.name })))

async function load() {
  if (!classId.value) return
  loading.value = true
  error.value = null
  try {
    data.value = await lessonPlansService.progress(Number(classId.value))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('lessonPlans.loadFailed')
  } finally {
    loading.value = false
  }
}
function onClass(value: string) {
  classId.value = value
  openSubject.value = null
  void load()
}
const overall = computed(() => {
  const s = data.value?.subjects ?? []
  const total = s.reduce((n, x) => n + x.total, 0)
  return total ? Math.round((s.reduce((n, x) => n + x.done, 0) / total) * 100) : 0
})

onMounted(async () => {
  options.value = await lessonPlansService.options().catch(() => options.value)
  const first = options.value.classes.find((c) => c.grade_level_id) ?? options.value.classes[0]
  if (first) {
    classId.value = String(first.id)
    await load()
  }
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-72 max-w-full" :model-value="classId" :options="classOptions" :placeholder="t('lessonPlans.chooseClass')" @update:model-value="onClass" />
      <BaseSpinner v-if="loading" class="h-4 w-4" />
      <p v-if="data?.subjects.length" class="text-sm text-neutral-600">{{ t('lessonPlans.progress.overall', { percent: overall }) }}</p>
    </div>
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="data && !data.has_grade" variant="info" class="mb-4">{{ t('lessonPlans.progress.noGrade') }}</BaseAlert>
    <BaseAlert v-else-if="data && data.subjects.length === 0" variant="info" class="mb-4">{{ t('lessonPlans.progress.noLessons') }}</BaseAlert>

    <div class="space-y-2">
      <div v-for="s in data?.subjects ?? []" :key="s.book_id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <button type="button" class="block w-full text-left" @click="openSubject = openSubject === s.book_id ? null : s.book_id">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="font-medium text-neutral-800">{{ s.subject }}</p>
            <p class="text-sm text-neutral-600">{{ t('lessonPlans.progress.line', { done: s.done, total: s.total, remaining: s.remaining }) }}</p>
          </div>
          <div class="mt-2 h-2 rounded-full bg-neutral-100">
            <div class="h-2 rounded-full" :class="s.percent >= 100 ? 'bg-green-500' : 'bg-primary-500'" :style="{ width: `${s.percent}%` }" />
          </div>
          <p class="mt-1 text-xs text-neutral-500">
            {{ s.percent }}%<template v-if="s.partial"> · {{ t('lessonPlans.progress.partial', { count: s.partial }) }}</template><template v-if="s.next"> · {{ t('lessonPlans.progress.next', { lesson: s.next }) }}</template>
          </p>
        </button>
        <ol v-if="openSubject === s.book_id" class="mt-3 space-y-1 border-t border-neutral-100 pt-2">
          <li v-for="(l, i) in s.lessons" :key="l.id" class="flex items-center gap-2 text-sm">
            <span class="w-6 text-right text-xs text-neutral-400">{{ i + 1 }}.</span>
            <span :class="l.status === 'done' ? 'text-green-700' : l.status === 'partial' ? 'text-amber-700' : 'text-neutral-400'">{{ l.status === 'done' ? '✓' : l.status === 'partial' ? '◐' : '○' }}</span>
            <span :class="l.status === 'done' ? 'text-neutral-800' : 'text-neutral-600'">{{ l.title }}</span>
          </li>
        </ol>
      </div>
    </div>
  </div>
</template>
