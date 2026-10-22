<script setup lang="ts">
import { computed, nextTick, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { onBeforeRouteLeave } from 'vue-router'

import GradeCorrectionDialog from '@/components/admin/GradeCorrectionDialog.vue'
import GradebookPicker from '@/components/admin/GradebookPicker.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import {
  gradebookService,
  resultApprovalsService,
  type CellInput,
  type HistoryRow,
  type SheetTarget,
  type GradebookPick,
  type GradeSheet,
  type SheetColumn,
  type SheetStatus,
  type SheetStudent,
} from '@/services/gradebook'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

/**
 * Examination > Grade Entry (docs/academics/gradebook.md, 7b): one class, one
 * subject, one term. "Whole class" is the bulk grid (every student and every
 * assessment on one page); "One student" goes through students one by one,
 * with a remark per score. Type A for absent. Results (%, score, band, rank)
 * are calculated by the server on every save.
 */
const { t, locale } = useI18n()
const confirmDialog = useConfirmDialogStore()

const pick = ref<GradebookPick | null>(null)
const sheet = ref<GradeSheet | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const saving = ref(false)
const saveError = ref<string | null>(null)
const saved = ref(false)
const mode = ref<'class' | 'student'>(window.innerWidth < 640 ? 'student' : 'class')
const studentIndex = ref(0)

/** Typed values: score text ("15", "A", "") or a competency level id, and remarks, per student and column. */
const draft = reactive<Record<number, Record<string, string>>>({})
const remarks = reactive<Record<number, Record<string, string>>>({})
const dirty = ref(new Set<string>())
const cellErrors = ref<Record<string, string>>({})

const km = computed(() => locale.value === 'km')
const pickName = (en: string | null | undefined, kh: string | null | undefined) => ((km.value ? kh || en : en || kh) ?? '').trim()
const isScore = computed(() => sheet.value?.scheme?.mode === 'score')
const editable = computed(() => !!sheet.value?.can_enter)
const status = computed<SheetStatus>(() => sheet.value?.sheet?.status ?? 'draft')
const statusVariant: Record<SheetStatus, 'neutral' | 'primary' | 'success' | 'warning'> = { draft: 'neutral', pending: 'primary', approved: 'success', returned: 'warning' }
const levelOptions = computed(() => sheet.value?.scheme?.levels ?? [])
const current = computed<SheetStudent | null>(() => sheet.value?.students[studentIndex.value] ?? null)

const ABSENT = ['a', 'ab', 'abs', 'អ']

/** The weight once per assessment: on its first time, with how many times are averaged. */
const weightNote = (c: SheetColumn) =>
  c.occurrence && c.occurrence > 1 ? '' : c.occurrences && c.occurrences > 1 ? ` · ${c.weight}% ${t('gradebook.entry.avgOf', { n: c.occurrences })}` : ` · ${c.weight}%`

function columnLabel(c: SheetColumn): string {
  const name = pickName(c.name, c.name_km)
  return c.occurrences && c.occurrences > 1 ? `${name} ${c.occurrence}` : name
}

function fill(data: GradeSheet) {
  sheet.value = data
  for (const key of Object.keys(draft)) delete draft[Number(key)]
  for (const key of Object.keys(remarks)) delete remarks[Number(key)]
  for (const s of data.students) {
    draft[s.id] = {}
    remarks[s.id] = {}
    for (const c of data.columns) {
      const cell = s.cells[c.key]
      draft[s.id]![c.key] = !cell ? '' : data.scheme?.mode === 'competency' ? (cell.level_id ? String(cell.level_id) : '') : cell.absent ? 'A' : cell.score === null ? '' : String(cell.score)
      remarks[s.id]![c.key] = cell?.remark ?? ''
    }
  }
  dirty.value = new Set()
  cellErrors.value = {}
  if (studentIndex.value >= data.students.length) studentIndex.value = 0
}

async function load() {
  const p = pick.value
  sheet.value = null
  saved.value = false
  saveError.value = null
  if (!p?.termId || !p.classId || !p.subjectId) return
  loading.value = true
  error.value = null
  try {
    fill(await gradebookService.sheet({ class_id: p.classId, book_id: p.subjectId, academic_term_id: p.termId }))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.loadFailed')
  } finally {
    loading.value = false
  }
}

async function onPick(p: GradebookPick) {
  if (dirty.value.size > 0 && !(await confirmDialog.confirm(t('gradebook.entry.discard')))) return
  pick.value = p
  await load()
}

function touch(studentId: number, key: string) {
  dirty.value.add(`${studentId}|${key}`)
  dirty.value = new Set(dirty.value)
  delete cellErrors.value[`${studentId}|${key}`]
  saved.value = false
}

/** Check one typed score before sending; returns a message or null. */
function check(col: SheetColumn, raw: string): string | null {
  const v = raw.trim().toLowerCase()
  if (v === '' || ABSENT.includes(v)) return null
  const n = Number(v.replace(',', '.'))
  if (Number.isNaN(n)) return t('gradebook.entry.notANumber')
  if (n < 0 || (col.max_score !== undefined && n > col.max_score)) return t('gradebook.entry.outOfRange', { max: col.max_score ?? '' })
  return null
}

function cellInput(studentId: number, col: SheetColumn): CellInput {
  const raw = (draft[studentId]?.[col.key] ?? '').trim()
  const remark = (remarks[studentId]?.[col.key] ?? '').trim() || null
  if (!isScore.value) return { student_id: studentId, criterion_id: col.criterion_id, level_id: raw ? Number(raw) : null, remark }
  const absent = ABSENT.includes(raw.toLowerCase())
  return {
    student_id: studentId,
    component_id: col.component_id,
    occurrence: col.occurrence,
    absent,
    score: absent || raw === '' ? null : Number(raw.replace(',', '.')),
    remark,
  }
}

async function save() {
  if (!sheet.value || !pick.value?.termId || !pick.value.classId || !pick.value.subjectId) return
  const cols = new Map(sheet.value.columns.map((c) => [c.key, c]))
  const keys = [...dirty.value]
  const errors: Record<string, string> = {}
  for (const k of keys) {
    const [sid, key] = k.split('|') as [string, string]
    const col = cols.get(key)
    if (isScore.value && col) {
      const msg = check(col, draft[Number(sid)]?.[key] ?? '')
      if (msg) errors[k] = msg
    }
  }
  if (Object.keys(errors).length) {
    cellErrors.value = errors
    saveError.value = t('gradebook.entry.fixCells')
    return
  }

  const cells = keys.map((k) => {
    const [sid, key] = k.split('|') as [string, string]
    return cellInput(Number(sid), cols.get(key)!)
  })
  if (!cells.length) return
  saving.value = true
  saveError.value = null
  try {
    fill(await gradebookService.save({ class_id: pick.value.classId, book_id: pick.value.subjectId, academic_term_id: pick.value.termId }, cells))
    saved.value = true
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) {
      const mapped: Record<string, string> = {}
      for (const [field, msgs] of Object.entries(e.errors)) {
        const m = field.match(/^cells\.(\d+)\./)
        if (m) mapped[keys[Number(m[1])]!] = msgs[0]!
      }
      cellErrors.value = mapped
      saveError.value = e.errors.sheet?.[0] ?? e.errors.scheme?.[0] ?? t('gradebook.entry.fixCells')
    } else {
      saveError.value = e instanceof ApiRequestError ? e.message : t('gradebook.saveFailed')
    }
  } finally {
    saving.value = false
  }
}

/** Enter (or arrow down) goes to the same column on the next row; arrow up the row above. */
async function move(event: KeyboardEvent, row: number, col: number) {
  const step = event.key === 'Enter' || event.key === 'ArrowDown' ? 1 : event.key === 'ArrowUp' ? -1 : 0
  if (!step) return
  event.preventDefault()
  await nextTick()
  document.getElementById(`gb-${row + step}-${col}`)?.focus()
}

// --- Submit for approval, corrections, history (7c) ----------------------------
const target = computed<SheetTarget | null>(() =>
  pick.value?.termId && pick.value.classId && pick.value.subjectId
    ? { class_id: pick.value.classId, book_id: pick.value.subjectId, academic_term_id: pick.value.termId }
    : null,
)
const submitting = ref(false)
const submitError = ref<string | null>(null)

async function submit() {
  if (!target.value) return
  if (dirty.value.size > 0) {
    submitError.value = t('gradebook.approval.saveFirst')
    return
  }
  if (!(await confirmDialog.confirm(t('gradebook.approval.submitConfirm')))) return
  submitting.value = true
  submitError.value = null
  try {
    await resultApprovalsService.submit(target.value)
    await load()
  } catch (e) {
    submitError.value = e instanceof ApiRequestError ? (e.fieldError('sheet') ?? e.message) : t('gradebook.saveFailed')
  } finally {
    submitting.value = false
  }
}

const correcting = ref(false)
const correctionStudent = ref<SheetStudent | null>(null)
const correctionColumn = ref<SheetColumn | null>(null)
const correctionSent = ref(false)
const isPendingCorrection = (studentId: number, key: string) => !!sheet.value?.pending_corrections.includes(`${studentId}|${key}`)

function askCorrection(s: SheetStudent, c: SheetColumn) {
  if (!sheet.value?.can_request_correction || isPendingCorrection(s.id, c.key)) return
  correctionStudent.value = s
  correctionColumn.value = c
  correctionSent.value = false
  correcting.value = true
}

async function onCorrectionSent() {
  correctionSent.value = true
  await load()
  correctionSent.value = true
}

/** What an approved cell shows (read-only): the score, A, or the level code. */
function cellText(s: SheetStudent, c: SheetColumn): string {
  const cell = s.cells[c.key]
  if (!cell) return '—'
  if (cell.absent) return 'A'
  if (!isScore.value) return levelOptions.value.find((l) => l.id === cell.level_id)?.code ?? '—'
  return cell.score === null ? '—' : String(cell.score)
}

const historyOpen = ref(false)
const history = ref<HistoryRow[]>([])
async function openHistory() {
  if (!target.value) return
  history.value = await resultApprovalsService.history(target.value).catch(() => [])
  historyOpen.value = true
}

const resultText = (s: SheetStudent) => {
  const r = s.result
  if (!r || r.percent === null) return '—'
  return `${r.score ?? '—'} · ${r.percent}%`
}

onBeforeRouteLeave((to, from) => (dirty.value.size > 0 && to.path !== from.path ? confirmDialog.confirm(t('gradebook.entry.discard')) : true))
</script>

<template>
  <div>
    <GradebookPicker with-subject class="mb-4" @change="onPick" />

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <p v-else-if="!sheet" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('gradebook.entry.pickFirst') }}
    </p>

    <template v-else>
      <!-- What this sheet is, and whether it can be changed. -->
      <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
        <BaseBadge :variant="statusVariant[status]">{{ t(`gradebook.status.${status}`) }}</BaseBadge>
        <span v-if="sheet.scheme" class="text-neutral-600">
          {{ sheet.scheme.name }} · {{ t(`gradebook.modes.${sheet.scheme.mode}`) }} · {{ t('gradebook.entry.outOf', { max: sheet.scheme.max_score }) }} ·
          {{ t('gradebook.entry.passMark', { n: sheet.pass_mark }) }}
        </span>
        <div class="ml-auto flex rounded-lg border border-neutral-200 p-0.5">
          <button
            v-for="m in ['class', 'student'] as const"
            :key="m"
            type="button"
            class="rounded-md px-3 py-1 text-sm font-medium"
            :class="mode === m ? 'bg-primary-100 text-primary-800' : 'text-neutral-600 hover:bg-neutral-50'"
            @click="mode = m"
          >
            {{ t(`gradebook.entry.mode.${m}`) }}
          </button>
        </div>
      </div>

      <BaseAlert v-if="!sheet.scheme" variant="warning" class="mb-4">{{ t('gradebook.entry.noScheme') }}</BaseAlert>
      <BaseAlert v-else-if="!sheet.can_enter && (status === 'pending' || status === 'approved')" variant="info" class="mb-4">{{ t(status === 'pending' ? 'gradebook.entry.lockedPending' : 'gradebook.entry.locked') }}</BaseAlert>
      <BaseAlert v-else-if="!sheet.can_enter" variant="info" class="mb-4">{{ t('gradebook.entry.viewOnly') }}</BaseAlert>
      <BaseAlert v-if="status === 'returned' && sheet.sheet?.decision_note" variant="warning" class="mb-4">
        {{ t('gradebook.entry.returned', { note: sheet.sheet.decision_note }) }}
      </BaseAlert>
      <p v-if="sheet.students.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('gradebook.entry.noStudents') }}
      </p>

      <template v-else-if="sheet.scheme">
        <!-- Whole class: every student and every assessment on one page. -->
        <div v-if="mode === 'class'" class="overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white">
          <table class="min-w-full text-sm">
            <thead class="bg-neutral-50 text-left text-xs text-neutral-600">
              <tr>
                <th class="sticky left-0 z-10 bg-neutral-50 px-3 py-2 font-medium">{{ t('gradebook.entry.student') }}</th>
                <th v-for="c in sheet.columns" :key="c.key" class="px-2 py-2 text-center font-medium whitespace-nowrap">
                  {{ columnLabel(c) }}
                  <span v-if="isScore" class="block font-normal text-neutral-400">/{{ c.max_score }}{{ weightNote(c) }}</span>
                </th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('gradebook.entry.result') }}</th>
                <th class="px-2 py-2 text-center font-medium">{{ t('gradebook.entry.band') }}</th>
                <th class="px-2 py-2 text-center font-medium">{{ t('gradebook.entry.rank') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(s, ri) in sheet.students" :key="s.id" class="border-t border-neutral-100">
                <td class="sticky left-0 z-10 bg-white px-3 py-1.5 whitespace-nowrap">
                  <span class="font-medium text-neutral-900">{{ s.name }}</span>
                  <span class="block text-xs text-neutral-400">{{ s.code }}</span>
                </td>
                <td v-for="(c, ci) in sheet.columns" :key="c.key" class="px-1 py-1 text-center">
                  <!-- Approved: read-only; a click asks for a correction. -->
                  <button
                    v-if="sheet.can_request_correction || status === 'approved'"
                    type="button"
                    class="w-16 rounded-md border px-1.5 py-1 text-center text-sm"
                    :class="
                      isPendingCorrection(s.id, c.key)
                        ? 'border-amber-400 bg-amber-50 text-amber-800'
                        : sheet.can_request_correction
                          ? 'border-neutral-200 bg-neutral-50 hover:border-primary-400 hover:bg-primary-50'
                          : 'cursor-default border-neutral-200 bg-neutral-50'
                    "
                    :title="isPendingCorrection(s.id, c.key) ? t('gradebook.corrections.waiting') : sheet.can_request_correction ? t('gradebook.corrections.clickToAsk') : ''"
                    @click="askCorrection(s, c)"
                  >
                    {{ cellText(s, c) }}
                  </button>
                  <template v-else-if="isScore">
                    <input
                      :id="`gb-${ri}-${ci}`"
                      v-model="draft[s.id]![c.key]"
                      :disabled="!editable"
                      inputmode="decimal"
                      class="w-16 rounded-md border px-1.5 py-1 text-center text-sm disabled:bg-neutral-50 disabled:text-neutral-600"
                      :class="cellErrors[`${s.id}|${c.key}`] ? 'border-red-500 bg-red-50' : dirty.has(`${s.id}|${c.key}`) ? 'border-primary-400 bg-primary-50' : 'border-neutral-300'"
                      :title="cellErrors[`${s.id}|${c.key}`] ?? ''"
                      @input="touch(s.id, c.key)"
                      @keydown="move($event, ri, ci)"
                    />
                  </template>
                  <select
                    v-else
                    :id="`gb-${ri}-${ci}`"
                    v-model="draft[s.id]![c.key]"
                    :disabled="!editable"
                    class="rounded-md border border-neutral-300 px-1.5 py-1 text-sm disabled:bg-neutral-50"
                    :class="dirty.has(`${s.id}|${c.key}`) ? 'border-primary-400 bg-primary-50' : ''"
                    @change="touch(s.id, c.key)"
                  >
                    <option value="">—</option>
                    <option v-for="l in levelOptions" :key="l.id" :value="String(l.id)">{{ l.code }} · {{ pickName(l.label, l.label_km) }}</option>
                  </select>
                </td>
                <td class="px-2 py-1 text-right whitespace-nowrap text-neutral-800">{{ resultText(s) }}</td>
                <td class="px-2 py-1 text-center">
                  <span v-if="s.result?.band" class="font-semibold" :class="s.result.passed === false ? 'text-red-600' : 'text-neutral-900'">{{ s.result.band }}</span>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-2 py-1 text-center text-neutral-700">{{ s.result?.rank ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- One student at a time, with a remark per score. -->
        <div v-else-if="current" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4">
          <div class="mb-3 flex items-center gap-2">
            <button type="button" class="rounded-lg border border-neutral-300 px-3 py-1.5 text-sm disabled:opacity-40" :disabled="studentIndex === 0" @click="studentIndex--">‹</button>
            <select v-model.number="studentIndex" class="min-w-0 flex-1 rounded-lg border border-neutral-300 px-2 py-1.5 text-sm">
              <option v-for="(s, i) in sheet.students" :key="s.id" :value="i">{{ i + 1 }}. {{ s.name }}</option>
            </select>
            <button type="button" class="rounded-lg border border-neutral-300 px-3 py-1.5 text-sm disabled:opacity-40" :disabled="studentIndex >= sheet.students.length - 1" @click="studentIndex++">›</button>
          </div>
          <div class="space-y-3">
            <div v-for="c in sheet.columns" :key="c.key" class="grid gap-2 sm:grid-cols-[1fr_8rem_1.2fr] sm:items-center">
              <label class="text-sm text-neutral-800">
                {{ columnLabel(c) }}
                <span v-if="isScore" class="text-xs text-neutral-400">/{{ c.max_score }}{{ weightNote(c) }}</span>
              </label>
              <div>
                <input
                  v-if="isScore"
                  v-model="draft[current.id]![c.key]"
                  :disabled="!editable"
                  inputmode="decimal"
                  class="w-full rounded-md border px-2 py-1.5 text-sm disabled:bg-neutral-50"
                  :class="cellErrors[`${current.id}|${c.key}`] ? 'border-red-500 bg-red-50' : 'border-neutral-300'"
                  @input="touch(current.id, c.key)"
                />
                <select v-else v-model="draft[current.id]![c.key]" :disabled="!editable" class="w-full rounded-md border border-neutral-300 px-2 py-1.5 text-sm" @change="touch(current.id, c.key)">
                  <option value="">—</option>
                  <option v-for="l in levelOptions" :key="l.id" :value="String(l.id)">{{ l.code }} · {{ pickName(l.label, l.label_km) }}</option>
                </select>
                <p v-if="cellErrors[`${current.id}|${c.key}`]" class="mt-1 text-xs text-red-600">{{ cellErrors[`${current.id}|${c.key}`] }}</p>
              </div>
              <button
                v-if="sheet.can_request_correction"
                type="button"
                class="text-left text-sm font-medium hover:underline"
                :class="isPendingCorrection(current.id, c.key) ? 'text-amber-700' : 'text-secondary-700'"
                :disabled="isPendingCorrection(current.id, c.key)"
                @click="askCorrection(current, c)"
              >
                {{ isPendingCorrection(current.id, c.key) ? t('gradebook.corrections.waiting') : t('gradebook.corrections.ask') }}
              </button>
              <input
                v-else
                v-model="remarks[current.id]![c.key]"
                :disabled="!editable"
                :placeholder="t('gradebook.entry.remark')"
                class="rounded-md border border-neutral-300 px-2 py-1.5 text-sm disabled:bg-neutral-50"
                @input="touch(current.id, c.key)"
              />
            </div>
          </div>
          <p class="mt-4 border-t border-neutral-100 pt-3 text-sm text-neutral-700">
            {{ t('gradebook.entry.result') }}: <span class="font-semibold">{{ resultText(current) }}</span>
            <span v-if="current.result?.band"> · {{ current.result.band }}</span>
            <span v-if="current.result?.rank"> · {{ t('gradebook.entry.rankN', { n: current.result.rank }) }}</span>
          </p>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
          <BaseButton v-if="sheet.can_submit" variant="outline" :loading="submitting" @click="submit">{{ t('gradebook.approval.submit') }}</BaseButton>
          <button v-if="sheet.history_count > 0" type="button" class="text-sm font-medium text-secondary-700 hover:underline" @click="openHistory">
            {{ t('gradebook.corrections.historyN', { n: sheet.history_count }) }}
          </button>
          <span v-if="submitError" class="text-sm text-red-600">{{ submitError }}</span>
          <span v-if="correctionSent" class="text-sm text-green-700">{{ t('gradebook.corrections.sent') }}</span>
          <span v-if="sheet.can_request_correction" class="text-xs text-neutral-500 sm:ml-auto">{{ t('gradebook.corrections.legend') }}</span>
        </div>
        <div v-if="editable" class="mt-3 flex flex-wrap items-center gap-3">
          <BaseButton :loading="saving" :disabled="dirty.size === 0" @click="save">{{ t('gradebook.entry.save', { n: dirty.size }) }}</BaseButton>
          <span v-if="saved" class="text-sm text-green-700">{{ t('gradebook.entry.saved') }}</span>
          <span v-if="saveError" class="text-sm text-red-600">{{ saveError }}</span>
          <span class="text-xs text-neutral-500 sm:ml-auto">{{ isScore ? t('gradebook.entry.legend') : t('gradebook.entry.legendCompetency') }}</span>
        </div>
      </template>
    </template>

    <GradeCorrectionDialog
      v-if="sheet && target"
      v-model="correcting"
      :sheet="sheet"
      :target="target"
      :student="correctionStudent"
      :column="correctionColumn"
      @sent="onCorrectionSent"
    />

    <BaseModal v-model="historyOpen" :title="t('gradebook.corrections.historyTitle')" size="lg">
      <p v-if="history.length === 0" class="text-sm text-neutral-500">{{ t('gradebook.corrections.noHistory') }}</p>
      <ul v-else class="divide-y divide-neutral-100 text-sm">
        <li v-for="h in history" :key="h.id" class="py-2">
          <p class="font-medium text-neutral-900">
            {{ h.student }} · {{ h.what }}: <span class="text-neutral-500 line-through">{{ h.old }}</span> → <span class="text-primary-700">{{ h.new }}</span>
          </p>
          <p class="text-neutral-600">{{ h.reason }}</p>
          <p class="text-xs text-neutral-400">{{ h.correction }} · {{ h.changed_by }} · {{ formatDateTime(h.changed_at) }}</p>
        </li>
      </ul>
    </BaseModal>
  </div>
</template>
