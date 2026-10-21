<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import LessonPlanModal from '@/components/admin/LessonPlanModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { lessonPlansService, type LessonOptions, type PlanRow } from '@/services/lessonPlans'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/** Lesson plans > Review: plans sent for review, oldest first — open one to approve or return it. */
const { t } = useI18n()

const options = ref<LessonOptions>({ me: null, sees_all: true, classes: [], subjects: [], teachers: [] })
const rows = ref<PlanRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    rows.value = await lessonPlansService.plans({ status: 'submitted' })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('lessonPlans.loadFailed')
  } finally {
    loading.value = false
  }
}

const open = ref(false)
const planId = ref<number | null>(null)
function openPlan(id: number) {
  planId.value = id
  open.value = true
}

onMounted(async () => {
  options.value = await lessonPlansService.options().catch(() => options.value)
  await load()
})
</script>

<template>
  <div>
    <p class="mb-3 text-sm text-neutral-500">{{ t('lessonPlans.review.hint') }}</p>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <BaseAlert v-else-if="error" variant="danger">{{ error }}</BaseAlert>
    <BaseAlert v-else-if="rows.length === 0" variant="success">{{ t('lessonPlans.review.none') }}</BaseAlert>
    <div class="space-y-2">
      <button v-for="r in rows" :key="r.id" type="button" class="flex w-full flex-wrap items-center gap-3 rounded-[--radius-card] border border-neutral-200 bg-white p-3 text-left shadow-[--shadow-card] hover:border-primary-300" @click="openPlan(r.id)">
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-neutral-800">{{ r.title }}</p>
          <p class="text-xs text-neutral-500">{{ [r.teacher, r.class, r.subject].filter(Boolean).join(' · ') }}<template v-if="r.files_count"> · 📎 {{ r.files_count }}</template></p>
        </div>
        <span class="text-sm text-neutral-600">{{ formatDate(r.planned_date) }}</span>
      </button>
    </div>

    <LessonPlanModal v-model="open" :plan-id="planId" :options="options" @changed="load" />
  </div>
</template>
