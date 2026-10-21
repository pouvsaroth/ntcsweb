<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { teachersService, type Substitution, type SubstitutionCandidates, type TeacherOptions } from '@/services/teachers'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Teacher > Substitute: who covers a class on a day its teacher is
 * away. Picking a class and a day shows which of its teachers are away
 * (leave, unavailable) and who is free to cover then.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('teachers.manage'))

const pad = (n: number) => String(n).padStart(2, '0')
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
const monday = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate() - ((d.getDay() + 6) % 7))
const weekStart = ref(monday(new Date()))
const from = computed(() => iso(weekStart.value))
const to = computed(() => iso(new Date(weekStart.value.getFullYear(), weekStart.value.getMonth(), weekStart.value.getDate() + 6)))

const options = ref<TeacherOptions>({ classes: [], subjects: [], academic_years: [] })
const rows = ref<Substitution[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    rows.value = await teachersService.substitutions(from.value, to.value)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('teacherMgmt.loadFailed')
  } finally {
    loading.value = false
  }
}
function shift(weeks: number) {
  const d = weekStart.value
  weekStart.value = new Date(d.getFullYear(), d.getMonth(), d.getDate() + weeks * 7)
  void load()
}

const time = (s: Substitution) => (s.start_time ? `${s.start_time}–${s.end_time}` : t('teacherMgmt.avail.allDay'))
const columns = computed(() => [
  { key: 'date', label: t('teacherMgmt.date') },
  { key: 'class', label: t('teacherMgmt.class') },
  { key: 'absent', label: t('teacherMgmt.subs.absent') },
  { key: 'substitute', label: t('teacherMgmt.subs.substitute') },
  { key: 'reason', label: t('teacherMgmt.reason') },
  ...(canManage.value ? [{ key: 'actions', label: t('teacherMgmt.actions'), align: 'text-right' }] : []),
])

async function remove(s: Substitution) {
  if (!(await confirmDialog.confirm({ message: t('teacherMgmt.subs.deleteConfirm'), danger: true }))) return
  await teachersService.removeSubstitution(s.id).catch(() => undefined)
  await load()
}

// --- Form ----------------------------------------------------------------------
const formOpen = ref(false)
const form = reactive({ class_id: '', date: iso(new Date()), start_time: '', end_time: '', absent_staff_id: '', substitute_staff_id: '', book_id: '', reason: '' })
const candidates = ref<SubstitutionCandidates | null>(null)
const checking = ref(false)
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)

const classOptions = computed(() => options.value.classes.map((c) => ({ value: String(c.id), label: c.academic_year ? `${c.name} · ${c.academic_year}` : c.name })))
const subjectOptions = computed(() => [{ value: '', label: '—' }, ...options.value.subjects.map((s) => ({ value: String(s.id), label: s.title }))])
const awayText = (why: string | null) => (why ? t(`teacherMgmt.subs.why.${why}`) : '')
const absentOptions = computed(() =>
  (candidates.value?.class_teachers ?? []).map((x) => ({ value: String(x.id), label: x.away ? `${x.name} — ${awayText(x.away)}` : x.name })),
)
const substituteOptions = computed(() => (candidates.value?.available ?? []).map((x) => ({ value: String(x.id), label: x.name })))

function open() {
  form.class_id = ''
  form.date = iso(new Date())
  form.start_time = ''
  form.end_time = ''
  form.absent_staff_id = ''
  form.substitute_staff_id = ''
  form.book_id = ''
  form.reason = ''
  candidates.value = null
  errors.value = {}
  formOpen.value = true
}

/** Class + day (+ time): who is away, who can cover. */
async function check() {
  if (!form.class_id || !form.date) return
  checking.value = true
  try {
    const c = await teachersService.candidates(Number(form.class_id), form.date, form.start_time || undefined, form.end_time || undefined)
    candidates.value = c
    if (!form.start_time && c.start_time) form.start_time = c.start_time
    if (!form.end_time && c.end_time) form.end_time = c.end_time
    const away = c.class_teachers.find((x) => x.away) ?? c.class_teachers[0]
    form.absent_staff_id = away ? String(away.id) : ''
    if (!c.available.some((x) => String(x.id) === form.substitute_staff_id)) form.substitute_staff_id = ''
  } catch (e) {
    errors.value = { class_id: [e instanceof ApiRequestError ? e.message : t('teacherMgmt.loadFailed')] }
  } finally {
    checking.value = false
  }
}

watch(
  () => [form.class_id, form.date],
  () => {
    if (formOpen.value) void check()
  },
)

async function save() {
  saving.value = true
  errors.value = {}
  try {
    await teachersService.createSubstitution({
      class_id: Number(form.class_id),
      absent_staff_id: Number(form.absent_staff_id),
      substitute_staff_id: Number(form.substitute_staff_id),
      date: form.date,
      start_time: form.start_time || null,
      end_time: form.end_time || null,
      book_id: form.book_id ? Number(form.book_id) : null,
      reason: form.reason.trim() || null,
    })
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else errors.value = { class_id: [e instanceof ApiRequestError ? e.message : t('teacherMgmt.saveFailed')] }
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  options.value = await teachersService.options().catch(() => options.value)
  await load()
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="flex items-center gap-1">
        <button type="button" class="rounded-lg border border-neutral-300 px-2.5 py-1.5 text-sm hover:bg-neutral-50" :aria-label="t('teacherMgmt.subs.previous')" @click="shift(-1)">‹</button>
        <button type="button" class="rounded-lg border border-neutral-300 px-2.5 py-1.5 text-sm hover:bg-neutral-50" :aria-label="t('teacherMgmt.subs.next')" @click="shift(1)">›</button>
      </div>
      <p class="text-sm font-medium text-neutral-800">{{ formatDate(from) }} – {{ formatDate(to) }}</p>
      <BaseSpinner v-if="loading" class="h-4 w-4" />
      <BaseButton v-if="canManage" class="ml-auto" @click="open">{{ t('teacherMgmt.subs.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="space-y-2 sm:hidden">
      <p v-if="!loading && rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('teacherMgmt.subs.empty') }}</p>
      <div v-for="row in rows" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
        <p class="text-sm font-semibold text-neutral-800">{{ row.class }} · {{ formatDate(row.date) }} {{ time(row) }}</p>
        <p class="text-xs text-neutral-500">{{ row.absent }} → <span class="font-medium text-neutral-800">{{ row.substitute }}</span></p>
        <p v-if="row.reason || row.subject" class="text-xs text-neutral-500">{{ [row.subject, row.reason].filter(Boolean).join(' · ') }}</p>
        <div v-if="canManage" class="mt-2 flex justify-end">
          <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('teacherMgmt.delete') }}</button>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="rows" row-key="id" :loading="loading" :empty-message="t('teacherMgmt.subs.empty')">
        <template #cell-date="{ row }">
          <p>{{ formatDate(row.date) }}</p>
          <p class="text-xs text-neutral-500">{{ time(row as Substitution) }}</p>
        </template>
        <template #cell-class="{ row }">
          <p class="font-medium text-neutral-800">{{ row.class }}</p>
          <p v-if="row.subject" class="text-xs text-neutral-500">{{ row.subject }}</p>
        </template>
        <template #cell-absent="{ row }">{{ row.absent }}</template>
        <template #cell-substitute="{ row }"><span class="font-medium text-neutral-800">{{ row.substitute }}</span></template>
        <template #cell-reason="{ row }">{{ row.reason || '—' }}</template>
        <template #cell-actions="{ row }">
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as Substitution)">{{ t('teacherMgmt.delete') }}</button>
        </template>
      </DataTable>
    </div>

    <BaseModal v-model="formOpen" :title="t('teacherMgmt.subs.add')">
      <form class="space-y-4" @submit.prevent="save">
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-model="form.class_id" :options="classOptions" :placeholder="t('teacherMgmt.chooseClass')" required :label="t('teacherMgmt.class')" :error="errors.class_id?.[0]" />
          <BaseInput v-model="form.date" type="date" required :label="t('teacherMgmt.date')" :error="errors.date?.[0]" />
          <BaseInput v-model="form.start_time" type="time" :label="t('teacherMgmt.startTime')" :error="errors.start_time?.[0]" @change="check" />
          <BaseInput v-model="form.end_time" type="time" :label="t('teacherMgmt.endTime')" :error="errors.end_time?.[0]" @change="check" />
        </div>
        <p v-if="checking" class="text-sm text-neutral-500">{{ t('teacherMgmt.subs.checking') }}</p>
        <template v-if="candidates">
          <BaseSelect
            v-model="form.absent_staff_id"
            :options="absentOptions"
            :placeholder="t('teacherMgmt.chooseTeacher')"
            required
            :label="t('teacherMgmt.subs.absent')"
            :hint="absentOptions.length === 0 ? t('teacherMgmt.subs.noClassTeacher') : undefined"
            :error="errors.absent_staff_id?.[0]"
          />
          <BaseSelect
            v-model="form.substitute_staff_id"
            :options="substituteOptions"
            :placeholder="t('teacherMgmt.chooseTeacher')"
            required
            :label="t('teacherMgmt.subs.substitute')"
            :hint="t('teacherMgmt.subs.freeN', { count: candidates.available.length })"
            :error="errors.substitute_staff_id?.[0]"
          />
          <details v-if="candidates.not_available.length" class="text-xs text-neutral-500">
            <summary class="cursor-pointer">{{ t('teacherMgmt.subs.notFreeN', { count: candidates.not_available.length }) }}</summary>
            <p v-for="x in candidates.not_available" :key="x.id" class="mt-1">{{ x.name }} — {{ x.busy_class ? t('teacherMgmt.subs.busyIn', { class: x.busy_class }) : awayText(x.why) }}</p>
          </details>
          <div class="grid gap-4 sm:grid-cols-2">
            <BaseSelect v-model="form.book_id" :options="subjectOptions" :label="t('teacherMgmt.subject')" />
            <BaseInput v-model="form.reason" :label="t('teacherMgmt.reason')" />
          </div>
        </template>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" :disabled="!form.absent_staff_id || !form.substitute_staff_id" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
