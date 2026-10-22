<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { booksService, type Book } from '@/services/books'
import {
  assessmentSchemesService,
  COMPONENT_KINDS,
  type AssessmentComponent,
  type AssessmentMode,
  type AssessmentScheme,
  type CompetencyLevel,
  type GradeBand,
} from '@/services/gradebook'
import { gradeLevelsService, type GradeLevel } from '@/services/schoolSetup'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * Examination > Assessment Setup (docs/academics/gradebook.md): how each grade
 * level is assessed — weighted components and letter bands, or competency
 * levels per criterion. Nothing about the Ministry's rules is built in.
 */
const { t, locale } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('assessment.manage'))

const schemes = ref<AssessmentScheme[]>([])
const grades = ref<GradeLevel[]>([])
const books = ref<Book[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const km = computed(() => locale.value === 'km')
const pick = (en: string | null | undefined, kh: string | null | undefined) => ((km.value ? kh || en : en || kh) ?? '').trim()

async function load() {
  loading.value = true
  error.value = null
  try {
    schemes.value = await assessmentSchemesService.list()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.loadFailed')
  } finally {
    loading.value = false
  }
}

const gradeName = (s: AssessmentScheme) => (s.grade_level ? s.grade_level.name : t('gradebook.setup.defaultScheme'))
const componentLine = (c: AssessmentComponent) =>
  `${pick(c.name, c.name_km)}${c.occurrences > 1 ? ` ×${c.occurrences}` : ''} · ${c.weight}%`
const levelLine = (l: CompetencyLevel) => `${l.code} ${pick(l.label, l.label_km)}`
const bandLine = (b: GradeBand) => `${b.letter} ≥ ${b.min_percent}%`
const isEmpty = (s: AssessmentScheme) => (s.mode === 'score' ? s.components.length === 0 && s.bands.length === 0 : s.levels.length === 0 && s.criteria.length === 0)

// --- Editor ---------------------------------------------------------------------
interface CriterionRow {
  id?: number
  book_id: string
  name: string
  name_km: string
}
interface ComponentRow {
  id?: number
  name: string
  name_km: string
  kind: string
  max_score: string
  weight: string
  occurrences: string
}
interface BandRow {
  id?: number
  letter: string
  label: string
  label_km: string
  min_percent: string
}
interface LevelRow {
  id?: number
  code: string
  label: string
  label_km: string
  points: string
  is_passing: boolean
}

const formOpen = ref(false)
const editing = ref<AssessmentScheme | null>(null)
const form = reactive({ grade_level_id: '', mode: 'score' as AssessmentMode, name: '', max_score: '100', decimals: '2', absent_counts_as_zero: true, notes: '' })
const components = ref<ComponentRow[]>([])
const bands = ref<BandRow[]>([])
const criteria = ref<CriterionRow[]>([])
const levels = ref<LevelRow[]>([])
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const usedGrades = computed(() => new Set(schemes.value.filter((s) => s.id !== editing.value?.id).map((s) => s.grade_level?.id ?? 0)))
const gradeOptions = computed(() => [
  ...(usedGrades.value.has(0) ? [] : [{ value: '', label: t('gradebook.setup.defaultScheme') }]),
  ...grades.value.filter((g) => !usedGrades.value.has(g.id)).map((g) => ({ value: String(g.id), label: g.name })),
])
const kindOptions = computed(() => COMPONENT_KINDS.map((k) => ({ value: k, label: t(`gradebook.kinds.${k}`) })))
const subjectOptions = computed(() => [{ value: '', label: t('gradebook.setup.allSubjects') }, ...books.value.map((b) => ({ value: String(b.id), label: b.title }))])
const weightsTotal = computed(() => Math.round(components.value.reduce((sum, c) => sum + (Number(c.weight) || 0), 0) * 100) / 100)
const err = (key: string) => errors.value[key]?.[0]

function open(scheme: AssessmentScheme | null) {
  editing.value = scheme
  form.grade_level_id = scheme?.grade_level ? String(scheme.grade_level.id) : (gradeOptions.value[0]?.value ?? '')
  form.mode = scheme?.mode ?? 'score'
  form.name = scheme?.name ?? ''
  form.max_score = String(scheme?.max_score ?? 100)
  form.decimals = String(scheme?.decimals ?? 2)
  form.absent_counts_as_zero = scheme?.absent_counts_as_zero ?? true
  form.notes = scheme?.notes ?? ''
  components.value = (scheme?.components ?? []).map((c) => ({
    id: c.id, name: c.name, name_km: c.name_km ?? '', kind: c.kind, max_score: String(c.max_score), weight: String(c.weight), occurrences: String(c.occurrences),
  }))
  bands.value = (scheme?.bands ?? []).map((b) => ({ id: b.id, letter: b.letter, label: b.label ?? '', label_km: b.label_km ?? '', min_percent: String(b.min_percent) }))
  criteria.value = (scheme?.criteria ?? []).map((c) => ({ id: c.id, book_id: c.book ? String(c.book.id) : '', name: c.name, name_km: c.name_km ?? '' }))
  levels.value = (scheme?.levels ?? []).map((l) => ({ id: l.id, code: l.code, label: l.label, label_km: l.label_km ?? '', points: String(l.points), is_passing: l.is_passing }))
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

const addComponent = () => components.value.push({ name: '', name_km: '', kind: 'monthly', max_score: form.max_score || '100', weight: '', occurrences: '1' })
const addBand = () => bands.value.push({ letter: '', label: '', label_km: '', min_percent: '' })
const addCriterion = () => criteria.value.push({ book_id: '', name: '', name_km: '' })
const addLevel = () => levels.value.push({ code: '', label: '', label_km: '', points: '', is_passing: true })

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    grade_level_id: form.grade_level_id ? Number(form.grade_level_id) : null,
    mode: form.mode,
    name: form.name.trim(),
    max_score: Number(form.max_score),
    decimals: Number(form.decimals),
    absent_counts_as_zero: form.absent_counts_as_zero,
    notes: form.notes.trim() || null,
    ...(form.mode === 'score'
      ? {
          components: components.value.map((c) => ({
            id: c.id, name: c.name.trim(), name_km: c.name_km.trim() || null, kind: c.kind as AssessmentComponent['kind'],
            max_score: Number(c.max_score), weight: Number(c.weight), occurrences: Number(c.occurrences),
          })),
          bands: bands.value.map((b) => ({ id: b.id, letter: b.letter.trim(), label: b.label.trim() || null, label_km: b.label_km.trim() || null, min_percent: Number(b.min_percent) })),
        }
      : {
          criteria: criteria.value.map((c) => ({ id: c.id, book_id: c.book_id ? Number(c.book_id) : null, name: c.name.trim(), name_km: c.name_km.trim() || null })),
          levels: levels.value.map((l) => ({ id: l.id, code: l.code.trim(), label: l.label.trim(), label_km: l.label_km.trim() || null, points: Number(l.points), is_passing: l.is_passing })),
        }),
  }
  try {
    if (editing.value) await assessmentSchemesService.update(editing.value.id, input)
    else await assessmentSchemesService.create(input)
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('gradebook.saveFailed')
  } finally {
    saving.value = false
  }
}

async function starter(scheme: AssessmentScheme) {
  if (!(await confirmDialog.confirm({ message: t('gradebook.setup.starterConfirm') }))) return
  try {
    await assessmentSchemesService.starter(scheme.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.saveFailed')
  }
}

async function remove(scheme: AssessmentScheme) {
  if (!(await confirmDialog.confirm({ message: t('gradebook.setup.deleteConfirm', { name: scheme.name }), danger: true }))) return
  try {
    await assessmentSchemesService.remove(scheme.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.deleteFailed')
  }
}

onMounted(async () => {
  ;[grades.value, books.value] = await Promise.all([
    gradeLevelsService.list(true).catch(() => []),
    auth.can('books.view') ? booksService.listAll().catch(() => []) : Promise.resolve([]),
  ])
  await load()
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-start gap-3">
      <p class="min-w-0 flex-1 text-sm text-neutral-600">{{ t('gradebook.setup.hint') }}</p>
      <BaseButton v-if="canManage" :disabled="gradeOptions.length === 0" @click="open(null)">{{ t('gradebook.setup.add') }}</BaseButton>
    </div>
    <BaseAlert variant="warning" class="mb-4">{{ t('gradebook.setup.ministryNote') }}</BaseAlert>
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="schemes.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('gradebook.setup.empty') }}
    </p>

    <!-- One card per setup; the same on phone and desktop (two per row from lg). -->
    <div v-else class="grid gap-3 lg:grid-cols-2">
      <div v-for="s in schemes" :key="s.id" class="flex flex-col rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="text-xs font-medium tracking-wide text-neutral-500 uppercase">{{ gradeName(s) }}</p>
            <p class="truncate font-semibold text-neutral-900">{{ s.name }}</p>
          </div>
          <BaseBadge :variant="s.mode === 'score' ? 'primary' : 'success'">{{ t(`gradebook.modes.${s.mode}`) }}</BaseBadge>
        </div>
        <p class="mt-1 text-xs text-neutral-500">{{ t('gradebook.setup.scale', { max: s.max_score }) }}</p>

        <template v-if="s.mode === 'score'">
          <p class="mt-3 text-xs font-medium text-neutral-500">{{ t('gradebook.setup.components') }} · {{ s.weights_total }}%</p>
          <ul class="mt-1 space-y-0.5 text-sm text-neutral-800">
            <li v-for="c in s.components" :key="c.id">{{ componentLine(c) }}</li>
          </ul>
          <p v-if="s.bands.length" class="mt-2 text-xs text-neutral-500">{{ s.bands.map(bandLine).join(' · ') }}</p>
          <p class="mt-1 text-xs text-neutral-500">{{ s.absent_counts_as_zero ? t('gradebook.setup.absentZero') : t('gradebook.setup.absentSkipped') }}</p>
        </template>
        <template v-else>
          <p class="mt-3 text-xs font-medium text-neutral-500">{{ t('gradebook.setup.levels') }}</p>
          <p class="mt-1 text-sm text-neutral-800">{{ s.levels.map(levelLine).join(' · ') || '—' }}</p>
          <p class="mt-2 text-xs font-medium text-neutral-500">{{ t('gradebook.setup.criteria') }}</p>
          <p class="mt-1 text-sm text-neutral-800">
            {{ s.criteria.map((c) => pick(c.name, c.name_km) + (c.book ? ` (${c.book.title})` : '')).join(' · ') || '—' }}
          </p>
        </template>

        <div v-if="s.warnings.length" class="mt-3 flex flex-wrap gap-1.5">
          <BaseBadge v-for="w in s.warnings" :key="w" variant="warning">{{ t(`gradebook.warnings.${w}`) }}</BaseBadge>
        </div>

        <div v-if="canManage" class="mt-auto flex flex-wrap gap-3 pt-3 text-sm">
          <button type="button" class="font-medium text-secondary-700 hover:underline" @click="open(s)">{{ t('gradebook.edit') }}</button>
          <button v-if="isEmpty(s)" type="button" class="font-medium text-secondary-700 hover:underline" @click="starter(s)">{{ t('gradebook.setup.starter') }}</button>
          <button type="button" class="ml-auto text-red-600 hover:underline" @click="remove(s)">{{ t('gradebook.delete') }}</button>
        </div>
      </div>
    </div>

    <BaseModal v-model="formOpen" :title="editing ? t('gradebook.setup.editTitle') : t('gradebook.setup.add')" size="lg">
      <form id="assessment-scheme-form" class="space-y-5" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-model="form.grade_level_id" :options="gradeOptions" :label="t('gradebook.setup.gradeLevel')" :error="err('grade_level_id')" />
          <BaseInput v-model="form.name" :label="t('gradebook.setup.name')" :placeholder="t('gradebook.setup.namePlaceholder')" :error="err('name')" required />
          <BaseInput v-model="form.max_score" type="number" :label="t('gradebook.setup.maxScore')" :hint="t('gradebook.setup.maxScoreHint')" :error="err('max_score')" required />
          <BaseSelect
            v-model="form.decimals"
            :options="['0', '1', '2'].map((d) => ({ value: d, label: t('gradebook.setup.decimalsN', { n: d }) }))"
            :label="t('gradebook.setup.decimals')"
          />
        </div>

        <fieldset>
          <legend class="mb-2 text-sm font-medium text-neutral-700">{{ t('gradebook.setup.mode') }}</legend>
          <div class="grid gap-2 sm:grid-cols-2">
            <label
              v-for="m in ['score', 'competency'] as const"
              :key="m"
              class="flex cursor-pointer flex-col rounded-xl border p-3 text-sm"
              :class="form.mode === m ? 'border-primary-500 bg-primary-50 ring-2 ring-primary-500' : 'border-neutral-200 hover:bg-neutral-50'"
            >
              <input v-model="form.mode" type="radio" :value="m" class="sr-only" />
              <span class="font-medium text-neutral-900">{{ t(`gradebook.modes.${m}`) }}</span>
              <span class="mt-0.5 text-xs text-neutral-500">{{ t(`gradebook.modeHints.${m}`) }}</span>
            </label>
          </div>
        </fieldset>

        <!-- Score mode: components + bands -->
        <template v-if="form.mode === 'score'">
          <section>
            <div class="mb-2 flex items-center justify-between gap-2">
              <h3 class="text-sm font-semibold text-neutral-900">{{ t('gradebook.setup.components') }}</h3>
              <span class="text-xs" :class="weightsTotal === 100 ? 'text-green-700' : 'text-amber-700'">{{ t('gradebook.setup.weightsTotal', { n: weightsTotal }) }}</span>
            </div>
            <div class="space-y-2">
              <div
                v-for="(c, i) in components"
                :key="i"
                class="grid grid-cols-2 gap-2 rounded-lg border border-neutral-200 p-2 sm:grid-cols-[1.2fr_1.2fr_1.4fr_0.6fr_0.6fr_0.5fr_auto] sm:items-start sm:border-0 sm:p-0"
              >
                <BaseInput v-model="c.name" :placeholder="t('gradebook.setup.componentName')" :error="err(`components.${i}.name`)" class="col-span-2 sm:col-span-1" />
                <BaseInput v-model="c.name_km" :placeholder="t('gradebook.setup.nameKm')" class="col-span-2 sm:col-span-1" />
                <BaseSelect v-model="c.kind" :options="kindOptions" :error="err(`components.${i}.kind`)" class="col-span-2 sm:col-span-1" />
                <BaseInput v-model="c.max_score" type="number" :placeholder="t('gradebook.setup.max')" :error="err(`components.${i}.max_score`)" />
                <BaseInput v-model="c.weight" type="number" :placeholder="t('gradebook.setup.weight')" :error="err(`components.${i}.weight`)" />
                <BaseInput v-model="c.occurrences" type="number" :placeholder="t('gradebook.setup.times')" :error="err(`components.${i}.occurrences`)" />
                <button type="button" class="justify-self-end py-2 text-sm text-red-600 hover:underline" @click="components.splice(i, 1)">{{ t('gradebook.remove') }}</button>
              </div>
              <p class="hidden text-xs text-neutral-500 sm:block">{{ t('gradebook.setup.componentColumns') }}</p>
            </div>
            <button type="button" class="mt-2 text-sm font-medium text-secondary-700 hover:underline" @click="addComponent">+ {{ t('gradebook.setup.addComponent') }}</button>
            <label class="mt-3 flex items-start gap-2 text-sm text-neutral-700">
              <input v-model="form.absent_counts_as_zero" type="checkbox" class="mt-0.5" />
              <span>{{ t('gradebook.setup.absentLabel') }}<span class="block text-xs text-neutral-500">{{ t('gradebook.setup.absentHint') }}</span></span>
            </label>
          </section>

          <section>
            <h3 class="mb-2 text-sm font-semibold text-neutral-900">{{ t('gradebook.setup.bands') }}</h3>
            <div class="space-y-2">
              <div v-for="(b, i) in bands" :key="i" class="grid grid-cols-[0.6fr_1fr_1fr_0.8fr_auto] items-start gap-2">
                <BaseInput v-model="b.letter" :placeholder="t('gradebook.setup.letter')" :error="err(`bands.${i}.letter`)" />
                <BaseInput v-model="b.label" :placeholder="t('gradebook.setup.label')" />
                <BaseInput v-model="b.label_km" :placeholder="t('gradebook.setup.labelKm')" />
                <BaseInput v-model="b.min_percent" type="number" :placeholder="t('gradebook.setup.minPercent')" :error="err(`bands.${i}.min_percent`)" />
                <button type="button" class="py-2 text-sm text-red-600 hover:underline" @click="bands.splice(i, 1)">×</button>
              </div>
            </div>
            <button type="button" class="mt-2 text-sm font-medium text-secondary-700 hover:underline" @click="addBand">+ {{ t('gradebook.setup.addBand') }}</button>
          </section>
        </template>

        <!-- Competency mode: levels + criteria -->
        <template v-else>
          <section>
            <h3 class="mb-2 text-sm font-semibold text-neutral-900">{{ t('gradebook.setup.levels') }}</h3>
            <div class="space-y-2">
              <div v-for="(l, i) in levels" :key="i" class="grid grid-cols-[0.5fr_1fr_1fr_0.6fr_auto_auto] items-start gap-2">
                <BaseInput v-model="l.code" :placeholder="t('gradebook.setup.code')" :error="err(`levels.${i}.code`)" />
                <BaseInput v-model="l.label" :placeholder="t('gradebook.setup.label')" :error="err(`levels.${i}.label`)" />
                <BaseInput v-model="l.label_km" :placeholder="t('gradebook.setup.labelKm')" />
                <BaseInput v-model="l.points" type="number" :placeholder="t('gradebook.setup.points')" :error="err(`levels.${i}.points`)" />
                <label class="flex items-center gap-1 py-2 text-xs text-neutral-700"><input v-model="l.is_passing" type="checkbox" /> {{ t('gradebook.setup.passing') }}</label>
                <button type="button" class="py-2 text-sm text-red-600 hover:underline" @click="levels.splice(i, 1)">×</button>
              </div>
            </div>
            <button type="button" class="mt-2 text-sm font-medium text-secondary-700 hover:underline" @click="addLevel">+ {{ t('gradebook.setup.addLevel') }}</button>
          </section>

          <section>
            <h3 class="mb-2 text-sm font-semibold text-neutral-900">{{ t('gradebook.setup.criteria') }}</h3>
            <div class="space-y-2">
              <div v-for="(c, i) in criteria" :key="i" class="grid grid-cols-2 items-start gap-2 sm:grid-cols-[1.3fr_1.3fr_1fr_auto]">
                <BaseInput v-model="c.name" :placeholder="t('gradebook.setup.criterionName')" :error="err(`criteria.${i}.name`)" />
                <BaseInput v-model="c.name_km" :placeholder="t('gradebook.setup.nameKm')" />
                <BaseSelect v-model="c.book_id" :options="subjectOptions" />
                <button type="button" class="justify-self-end py-2 text-sm text-red-600 hover:underline" @click="criteria.splice(i, 1)">{{ t('gradebook.remove') }}</button>
              </div>
            </div>
            <button type="button" class="mt-2 text-sm font-medium text-secondary-700 hover:underline" @click="addCriterion">+ {{ t('gradebook.setup.addCriterion') }}</button>
          </section>
        </template>

        <BaseInput v-model="form.notes" :label="t('gradebook.setup.notes')" :hint="t('gradebook.setup.notesHint')" />
      </form>
      <template #footer>
        <BaseButton variant="ghost" @click="formOpen = false">{{ t('gradebook.cancel') }}</BaseButton>
        <BaseButton type="submit" form="assessment-scheme-form" :loading="saving">{{ t('gradebook.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
