<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { gradeLevelsService, promotionsService, type GradeLevel, type PromotionAction, type PromotionStudent, type PromotionView } from '@/services/schoolSetup'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * School setup > Promote students: pick a class, see each student checked
 * against the grade's promotion rule (average, attendance, failed subjects),
 * then promote the chosen ones into a class of the next grade — or keep them
 * in the grade, or mark them graduated. Each student is decided once.
 * Classes with gradebook results are checked on each subject's approved
 * year average (Examination 7e); other classes on exam applications.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canRun = computed(() => auth.can('student-promotions.run'))

const grades = ref<GradeLevel[]>([])
const gradeId = ref('')
const classes = ref<{ id: number; name: string; status: string; academic_year: string | null; students_count: number }[]>([])
const classId = ref('')
const view = ref<PromotionView | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

const gradeOptions = computed(() => grades.value.map((g) => ({ value: String(g.id), label: g.name })))
const classOptions = computed(() =>
  classes.value.map((c) => ({ value: String(c.id), label: `${c.name}${c.academic_year ? ` · ${c.academic_year}` : ''} (${t('schoolSetup.promote.studentsN', { count: c.students_count })})` })),
)

async function onGrade(value: string) {
  gradeId.value = value
  classId.value = ''
  view.value = null
  classes.value = value ? await promotionsService.classes(Number(value)).catch(() => []) : []
}

/** Each student's choice, by enrollment: promote / repeat / graduate, or not now (''). */
const choice = ref<Record<number, PromotionAction | ''>>({})
const toClassId = ref('')
const repeatClassId = ref('')

function defaults(students: PromotionStudent[], lastGrade: boolean) {
  choice.value = Object.fromEntries(students.filter((s) => !s.done).map((s) => [s.enrollment_id, s.passes ? (lastGrade ? 'graduate' : 'promote') : 'repeat']))
}

async function load() {
  if (!classId.value) return
  loading.value = true
  error.value = null
  try {
    view.value = await promotionsService.show(Number(classId.value))
    defaults(view.value.students, view.value.next_grade_level === null)
    toClassId.value = view.value.promote_classes.length === 1 ? String(view.value.promote_classes[0]!.id) : ''
    repeatClassId.value = view.value.repeat_classes.length === 1 ? String(view.value.repeat_classes[0]!.id) : ''
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.loadFailed')
  } finally {
    loading.value = false
  }
}

function onClass(value: string) {
  classId.value = value
  notice.value = null
  void load()
}

const actionOptions = computed(() => [
  { value: '', label: t('schoolSetup.promote.actions.none') },
  ...(view.value?.next_grade_level ? [{ value: 'promote', label: t('schoolSetup.promote.actions.promote', { grade: view.value.next_grade_level.name }) }] : []),
  { value: 'repeat', label: t('schoolSetup.promote.actions.repeat') },
  { value: 'graduate', label: t('schoolSetup.promote.actions.graduate') },
])
const classPick = (list: PromotionView['promote_classes']) => [
  { value: '', label: '—' },
  ...list.map((c) => ({ value: String(c.id), label: `${c.name}${c.academic_year ? ` · ${c.academic_year}` : ''}` })),
]

const pending = computed(() => (view.value?.students ?? []).filter((s) => !s.done))
const chosen = computed(() => pending.value.filter((s) => choice.value[s.enrollment_id]))
const counts = computed(() => ({
  passes: pending.value.filter((s) => s.passes).length,
  fails: pending.value.filter((s) => !s.passes).length,
  promote: chosen.value.filter((s) => choice.value[s.enrollment_id] === 'promote').length,
  repeat: chosen.value.filter((s) => choice.value[s.enrollment_id] === 'repeat').length,
  graduate: chosen.value.filter((s) => choice.value[s.enrollment_id] === 'graduate').length,
}))
const missingTarget = computed(() => counts.value.promote > 0 && !toClassId.value)

const num = (n: number | null, suffix = '') => (n === null ? '—' : `${n}${suffix}`)
const reasonText = (s: PromotionStudent) => s.reasons.map((r) => t(`schoolSetup.promote.reasons.${r}`)).join(' · ')
const doneText = (s: PromotionStudent) =>
  s.done ? `${t(`schoolSetup.promote.results.${s.done.result}`)}${s.done.to_class ? ` → ${s.done.to_class}` : ''}` : ''

const applying = ref(false)
async function apply() {
  if (!view.value || chosen.value.length === 0) return
  const message = t('schoolSetup.promote.confirm', counts.value)
  if (!(await confirmDialog.confirm({ message }))) return
  applying.value = true
  error.value = null
  try {
    const r = await promotionsService.apply({
      class_id: view.value.class.id,
      to_class_id: toClassId.value ? Number(toClassId.value) : null,
      repeat_class_id: repeatClassId.value ? Number(repeatClassId.value) : null,
      students: chosen.value.map((s) => ({ enrollment_id: s.enrollment_id, action: choice.value[s.enrollment_id] as PromotionAction })),
    })
    notice.value = t('schoolSetup.promote.done', r)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (e.errors ? Object.values(e.errors)[0]?.[0] ?? e.message : e.message) : t('schoolSetup.saveFailed')
  } finally {
    applying.value = false
  }
}

onMounted(async () => {
  grades.value = await gradeLevelsService.list(true).catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-end gap-2">
      <BaseSelect class="w-48" :model-value="gradeId" :options="gradeOptions" :placeholder="t('schoolSetup.chooseGrade')" @update:model-value="onGrade" />
      <BaseSelect class="w-72 max-w-full" :model-value="classId" :options="classOptions" :disabled="!gradeId" :placeholder="t('schoolSetup.promote.chooseClass')" @update:model-value="onClass" />
      <BaseSpinner v-if="loading" class="h-4 w-4" />
    </div>

    <BaseAlert v-if="grades.length === 0" variant="info" class="mb-4">
      {{ t('schoolSetup.noGrades') }}
      <RouterLink to="/admin/school-setup/grade-levels" class="font-medium underline">{{ t('schoolSetup.tabs.gradeLevels') }}</RouterLink>
    </BaseAlert>
    <p v-else-if="gradeId && classes.length === 0" class="mb-4 text-sm text-neutral-500">{{ t('schoolSetup.promote.noClasses') }}</p>
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="success" class="mb-4">{{ notice }}</BaseAlert>

    <template v-if="view">
      <!-- The rule this class is checked against -->
      <div class="mb-4 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <p class="text-sm font-semibold text-neutral-800">{{ t('schoolSetup.promote.rule') }}</p>
        <p v-if="view.rule" class="mt-1 text-sm text-neutral-600">
          {{ t('schoolSetup.promote.ruleLine', {
            average: num(view.rule.min_average, '%'),
            attendance: num(view.rule.min_attendance, '%'),
            failed: num(view.rule.max_failed_subjects),
            pass: view.rule.pass_mark,
          }) }}
          <span class="text-neutral-400">({{ view.rule.grade_level ?? t('schoolSetup.rules.allGrades') }})</span>
        </p>
        <p v-else class="mt-1 text-sm text-amber-700">
          {{ t('schoolSetup.promote.noRule') }}
          <RouterLink to="/admin/school-setup/promotion-rules" class="font-medium underline">{{ t('schoolSetup.tabs.promotionRules') }}</RouterLink>
        </p>
        <p class="mt-2 text-xs text-neutral-500">
          {{ view.source === 'gradebook' ? t('schoolSetup.promote.sourceGradebook') : t('schoolSetup.promote.sourceExams') }}
        </p>
        <p v-if="view.unapproved_sheets > 0" class="mt-1 text-xs text-amber-700">
          {{ t('schoolSetup.promote.unapproved', { count: view.unapproved_sheets }) }}
          <RouterLink :to="{ path: '/admin/examination/approval', query: { class: view.class.id } }" class="font-medium underline">{{ t('gradebook.tabs.approval') }}</RouterLink>
        </p>
        <p class="mt-2 text-sm text-neutral-600">
          {{ t('schoolSetup.promote.summary', { passes: counts.passes, fails: counts.fails }) }}
          <template v-if="view.next_grade_level"> · {{ t('schoolSetup.promote.nextGrade', { grade: view.next_grade_level.name }) }}</template>
          <template v-else> · {{ t('schoolSetup.promote.lastGrade') }}</template>
        </p>
      </div>

      <!-- Where they go -->
      <div v-if="canRun && pending.length" class="mb-4 grid gap-3 sm:grid-cols-2">
        <BaseSelect
          v-if="view.next_grade_level"
          v-model="toClassId"
          :options="classPick(view.promote_classes)"
          :label="t('schoolSetup.promote.toClass', { grade: view.next_grade_level.name })"
          :hint="view.promote_classes.length === 0 ? t('schoolSetup.promote.createClassFirst', { grade: view.next_grade_level.name }) : undefined"
        />
        <BaseSelect v-model="repeatClassId" :options="classPick(view.repeat_classes)" :label="t('schoolSetup.promote.repeatClass')" :hint="t('schoolSetup.promote.repeatClassHint')" />
      </div>

      <p v-if="view.students.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('schoolSetup.promote.noStudents') }}
      </p>

      <!-- Cards on a phone (below sm) -->
      <div class="space-y-2 sm:hidden">
        <div v-for="s in view.students" :key="s.enrollment_id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ s.student_name }} <span class="text-xs font-normal text-neutral-500">{{ s.student_code }}</span></p>
            <BaseBadge v-if="s.done" variant="neutral">{{ doneText(s) }}</BaseBadge>
            <BaseBadge v-else :variant="s.passes ? 'success' : 'danger'">{{ s.passes ? t('schoolSetup.promote.passes') : t('schoolSetup.promote.fails') }}</BaseBadge>
          </div>
          <p class="text-xs text-neutral-500">
            {{ t('schoolSetup.promote.average') }} {{ num(s.average) }} · {{ t('schoolSetup.promote.attendance') }} {{ num(s.attendance_rate, '%') }} ·
            {{ t('schoolSetup.promote.failedN', { count: s.failed_subjects ?? 0 }) }}
          </p>
          <p v-if="!s.passes && !s.done" class="text-xs text-red-600">{{ reasonText(s) }}</p>
          <BaseSelect v-if="canRun && !s.done" v-model="choice[s.enrollment_id]" class="mt-2" :options="actionOptions" />
        </div>
      </div>

      <!-- Table (sm and up) -->
      <div v-if="view.students.length" class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card] sm:block">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
          <thead class="bg-neutral-50 text-left text-xs font-medium tracking-wide text-neutral-500 uppercase">
            <tr>
              <th class="px-4 py-3">{{ t('schoolSetup.promote.student') }}</th>
              <th class="px-4 py-3">{{ t('schoolSetup.promote.average') }}</th>
              <th class="px-4 py-3">{{ t('schoolSetup.promote.attendance') }}</th>
              <th class="px-4 py-3">{{ t('schoolSetup.promote.failed') }}</th>
              <th class="px-4 py-3">{{ t('schoolSetup.promote.result') }}</th>
              <th v-if="canRun" class="px-4 py-3">{{ t('schoolSetup.promote.decision') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="s in view.students" :key="s.enrollment_id">
              <td class="px-4 py-2">
                <p class="font-medium text-neutral-800">{{ s.student_name }}</p>
                <p class="text-xs text-neutral-500">{{ s.student_code }}</p>
              </td>
              <td class="px-4 py-2">{{ num(s.average) }}<span v-if="s.subjects" class="text-xs text-neutral-400"> ({{ t('schoolSetup.promote.subjectsN', { count: s.subjects }) }})</span></td>
              <td class="px-4 py-2">{{ num(s.attendance_rate, '%') }}</td>
              <td class="px-4 py-2">{{ num(s.failed_subjects) }}</td>
              <td class="px-4 py-2">
                <BaseBadge v-if="s.done" variant="neutral">{{ doneText(s) }}</BaseBadge>
                <template v-else>
                  <BaseBadge :variant="s.passes ? 'success' : 'danger'">{{ s.passes ? t('schoolSetup.promote.passes') : t('schoolSetup.promote.fails') }}</BaseBadge>
                  <p v-if="!s.passes" class="mt-0.5 text-xs text-red-600">{{ reasonText(s) }}</p>
                </template>
              </td>
              <td v-if="canRun" class="px-4 py-2">
                <BaseSelect v-if="!s.done" v-model="choice[s.enrollment_id]" class="w-52" :options="actionOptions" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="canRun && pending.length" class="mt-4 flex flex-wrap items-center justify-end gap-3">
        <p class="text-sm text-neutral-600">{{ t('schoolSetup.promote.chosen', counts) }}</p>
        <p v-if="missingTarget" class="text-sm text-red-600">{{ t('schoolSetup.promote.needTarget') }}</p>
        <BaseButton :loading="applying" :disabled="chosen.length === 0 || missingTarget" @click="apply">{{ t('schoolSetup.promote.apply') }}</BaseButton>
      </div>
    </template>
  </div>
</template>
