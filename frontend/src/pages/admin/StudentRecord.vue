<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { studentRecordService, type StudentRecord, type StudentStatus } from '@/services/students'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { genderLabel } from '@/utils/gender'

/**
 * Students > Student record (docs/academics/student-record.md): profile and
 * guardians at a glance, then the student's history — classes by academic
 * year, results (scores, attendance, promotion), status changes and class or
 * school transfers. Profile and guardians are edited in the Student form.
 */
const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const canUpdate = computed(() => auth.can('students.update'))

const id = computed(() => Number(route.params.id))
const data = ref<StudentRecord | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    data.value = await studentRecordService.get(id.value)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('studentRecord.loadFailed')
  } finally {
    loading.value = false
  }
}

const statusVariant = (s: string) => (s === 'active' ? 'success' : s === 'graduated' ? 'primary' : s === 'withdrawn' ? 'danger' : s === 'transferred' ? 'warning' : 'neutral')
const statusText = (s: string | null) => (s ? t(`studentRecord.statuses.${s}`) : '—')
const enrollmentStatus = (s: string) => t(`admin.enrollments.status${s.split('_').map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join('')}`)

/** The classes, newest year first, grouped by academic year ("No year" last). */
const byYear = computed(() => {
  const groups = new Map<string, StudentRecord['classes']>()
  for (const c of data.value?.classes ?? []) {
    const key = c.academic_year ?? ''
    groups.set(key, [...(groups.get(key) ?? []), c])
  }
  return [...groups.entries()].sort(([a], [b]) => (a === '' ? 1 : b === '' ? -1 : b.localeCompare(a)))
})

// --- Change status -----------------------------------------------------------------
const statusOpen = ref(false)
const form = reactive({ status: '' as StudentStatus | '', effective_date: '', reason: '', to_school: '', end_classes: true })
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)
const statusOptions = computed(() =>
  (['active', 'transferred', 'withdrawn', 'graduated'] as StudentStatus[])
    .filter((s) => s !== data.value?.student.status)
    .map((s) => ({ value: s, label: statusText(s) })),
)

function openStatus() {
  const today = new Date()
  form.status = statusOptions.value[0]?.value ?? ''
  form.effective_date = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`
  form.reason = ''
  form.to_school = ''
  form.end_classes = true
  errors.value = {}
  statusOpen.value = true
}

async function saveStatus() {
  saving.value = true
  errors.value = {}
  try {
    const r = await studentRecordService.changeStatus(id.value, {
      status: form.status as StudentStatus,
      effective_date: form.effective_date,
      reason: form.reason.trim() || null,
      to_school: form.status === 'transferred' ? form.to_school.trim() || null : null,
      end_classes: form.end_classes,
    })
    notice.value = t('studentRecord.statusChanged', { status: statusText(r.status), count: r.ended_classes })
    statusOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else errors.value = { status: [e instanceof ApiRequestError ? e.message : t('studentRecord.saveFailed')] }
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-5xl">
    <RouterLink to="/admin/students" class="text-sm text-secondary-700 hover:underline">← {{ t('studentRecord.back') }}</RouterLink>

    <div v-if="loading && !data" class="flex justify-center py-16"><BaseSpinner /></div>
    <BaseAlert v-else-if="error" variant="danger" class="mt-4">{{ error }}</BaseAlert>

    <template v-else-if="data">
      <!-- Profile -->
      <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-xl font-semibold text-neutral-900">{{ data.student.name }}</h1>
          <p class="mt-0.5 text-sm text-neutral-500">
            <span class="font-mono">{{ data.student.student_code }}</span>
            <template v-if="data.student.english_name"> · {{ data.student.english_name }}</template>
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <BaseBadge :variant="statusVariant(data.student.status)">{{ statusText(data.student.status) }}</BaseBadge>
          <BaseButton v-if="canUpdate && data.student.status !== 'pending'" variant="outline" size="sm" @click="openStatus">{{ t('studentRecord.changeStatus') }}</BaseButton>
          <BaseButton v-if="canUpdate" size="sm" :to="`/admin/students/${data.student.id}/edit`">{{ t('studentRecord.editProfile') }}</BaseButton>
        </div>
      </div>
      <BaseAlert v-if="notice" variant="success" class="mt-3">{{ notice }}</BaseAlert>

      <div class="mt-4 grid gap-4 md:grid-cols-2">
        <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <h2 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('studentRecord.profile') }}</h2>
          <dl class="grid grid-cols-[8rem_1fr] gap-y-1 text-sm">
            <dt class="text-neutral-500">{{ t('studentRecord.gender') }}</dt><dd>{{ data.student.gender ? genderLabel(data.student.gender) : '—' }}</dd>
            <dt class="text-neutral-500">{{ t('studentRecord.dateOfBirth') }}</dt><dd>{{ formatDate(data.student.date_of_birth) }}</dd>
            <dt class="text-neutral-500">{{ t('studentRecord.phone') }}</dt><dd>{{ data.student.phone || '—' }}</dd>
            <dt class="text-neutral-500">{{ t('studentRecord.since') }}</dt><dd>{{ formatDate(data.student.enrollment_date) }}</dd>
          </dl>
        </section>
        <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <h2 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('studentRecord.guardians') }}</h2>
          <p v-if="data.student.guardians.length === 0" class="text-sm text-neutral-500">{{ t('studentRecord.noGuardians') }}</p>
          <div v-for="(g, i) in data.student.guardians" :key="i" class="border-b border-neutral-100 py-1.5 text-sm last:border-0">
            <p class="font-medium text-neutral-800">{{ g.name }} <span v-if="g.type" class="font-normal text-neutral-500">({{ g.type }})</span></p>
            <p class="text-xs text-neutral-500">{{ [g.phone, g.email, g.address].filter(Boolean).join(' · ') || '—' }}</p>
          </div>
        </section>
      </div>

      <!-- Classes and results, by academic year -->
      <h2 class="mt-6 mb-2 text-base font-semibold text-neutral-900">{{ t('studentRecord.academicHistory') }}</h2>
      <p v-if="data.classes.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('studentRecord.noClasses') }}</p>
      <div v-for="[year, rows] in byYear" :key="year" class="mb-4">
        <p class="mb-1.5 text-sm font-semibold text-neutral-700">{{ year || t('studentRecord.noYear') }}</p>
        <div class="space-y-2">
          <div v-for="c in rows" :key="c.enrollment_id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <RouterLink :to="`/admin/classes/${c.class_id}/students`" class="font-medium text-primary-700 hover:underline">{{ c.class }}</RouterLink>
                <p class="text-xs text-neutral-500">{{ [c.grade_level, c.course, c.enrollments_code, formatDate(c.enrolled_at)].filter(Boolean).join(' · ') }}</p>
              </div>
              <div class="flex flex-wrap gap-1.5">
                <BaseBadge variant="neutral">{{ enrollmentStatus(c.status) }}</BaseBadge>
                <BaseBadge v-if="c.promotion" :variant="c.promotion.result === 'repeated' ? 'warning' : 'success'">
                  {{ t(`studentRecord.promotion.${c.promotion.result}`) }}<template v-if="c.promotion.to_class"> → {{ c.promotion.to_class }}</template>
                </BaseBadge>
              </div>
            </div>
            <div class="mt-3 grid grid-cols-3 gap-2 text-center">
              <div class="rounded-lg bg-neutral-50 p-2">
                <p class="text-xs text-neutral-500">{{ t('studentRecord.average') }}</p>
                <p class="text-lg font-semibold text-neutral-900">{{ c.average ?? '—' }}</p>
              </div>
              <div class="rounded-lg bg-neutral-50 p-2">
                <p class="text-xs text-neutral-500">{{ t('studentRecord.attendance') }}</p>
                <p class="text-lg font-semibold text-neutral-900">{{ c.attendance_rate != null ? `${c.attendance_rate}%` : '—' }}</p>
              </div>
              <div class="rounded-lg bg-neutral-50 p-2">
                <p class="text-xs text-neutral-500">{{ t('studentRecord.absentDays') }}</p>
                <p class="text-lg font-semibold text-neutral-900">{{ c.absent_days }}</p>
              </div>
            </div>
            <div v-if="c.subjects.length" class="mt-3 flex flex-wrap gap-1.5">
              <span v-for="(s, i) in c.subjects" :key="i" class="rounded-full px-2.5 py-0.5 text-xs" :class="s.score >= 50 ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'">
                {{ s.subject ?? t('studentRecord.exam') }}: {{ s.score }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-6 grid gap-4 md:grid-cols-2">
        <!-- Status history -->
        <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <h2 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('studentRecord.statusHistory') }}</h2>
          <p v-if="data.status_history.length === 0" class="text-sm text-neutral-500">{{ t('studentRecord.noStatusHistory') }}</p>
          <div v-for="(h, i) in data.status_history" :key="i" class="border-b border-neutral-100 py-1.5 text-sm last:border-0">
            <p class="text-neutral-800">{{ formatDate(h.date) }} · {{ statusText(h.from) }} → <span class="font-medium">{{ statusText(h.to) }}</span></p>
            <p v-if="h.reason || h.to_school" class="text-xs text-neutral-500">{{ [h.to_school, h.reason].filter(Boolean).join(' · ') }}</p>
          </div>
        </section>
        <!-- Transfers -->
        <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <h2 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('studentRecord.transfers') }}</h2>
          <p v-if="data.transfers.length === 0" class="text-sm text-neutral-500">{{ t('studentRecord.noTransfers') }}</p>
          <div v-for="(tr, i) in data.transfers" :key="i" class="border-b border-neutral-100 py-1.5 text-sm last:border-0">
            <p class="text-neutral-800">
              {{ formatDate(tr.date) }} ·
              <template v-if="tr.kind === 'class'">{{ t('studentRecord.classTransfer', { from: tr.from ?? '—', to: tr.to ?? '—' }) }}</template>
              <template v-else>{{ t('studentRecord.schoolTransfer', { to: tr.to ?? '—' }) }}</template>
            </p>
            <p v-if="tr.reason" class="text-xs text-neutral-500">{{ tr.reason }}</p>
          </div>
        </section>
      </div>
    </template>

    <BaseModal v-model="statusOpen" :title="t('studentRecord.changeStatus')">
      <form class="space-y-4" @submit.prevent="saveStatus">
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-model="form.status" :options="statusOptions" required :label="t('studentRecord.newStatus')" :error="errors.status?.[0]" />
          <BaseInput v-model="form.effective_date" type="date" required :label="t('studentRecord.date')" :error="errors.effective_date?.[0]" />
        </div>
        <BaseInput v-if="form.status === 'transferred'" v-model="form.to_school" required :label="t('studentRecord.toSchool')" :error="errors.to_school?.[0]" />
        <BaseInput v-model="form.reason" :required="form.status === 'transferred' || form.status === 'withdrawn'" :label="t('studentRecord.reason')" :error="errors.reason?.[0]" />
        <label v-if="form.status && form.status !== 'active'" class="flex items-start gap-2 text-sm text-neutral-700">
          <input v-model="form.end_classes" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('studentRecord.endClasses') }}
        </label>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="statusOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" :disabled="!form.status" @click="saveStatus">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
