<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { teachersService, type Homeroom, type Teacher, type TeacherOptions } from '@/services/teachers'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/** HRM > Teacher > Homeroom: the homeroom teacher of each class; changes save at once. */
const { t } = useI18n()
const auth = useAuthStore()
const canManage = computed(() => auth.can('teachers.manage'))

const teachers = ref<Teacher[]>([])
const years = ref<TeacherOptions['academic_years']>([])
const yearId = ref('')
const rows = ref<Homeroom[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const saved = ref<number | null>(null)

const yearOptions = computed(() => [{ value: '', label: t('teacherMgmt.allYears') }, ...years.value.map((y) => ({ value: String(y.id), label: y.name }))])
const teacherOptions = computed(() => [{ value: '', label: t('teacherMgmt.home.none') }, ...teachers.value.map((x) => ({ value: String(x.id), label: x.name }))])
const assigned = computed(() => rows.value.filter((r) => r.homeroom_staff_id).length)

async function load() {
  loading.value = true
  error.value = null
  try {
    rows.value = await teachersService.homerooms(yearId.value ? Number(yearId.value) : undefined)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('teacherMgmt.loadFailed')
  } finally {
    loading.value = false
  }
}
function onYear(value: string) {
  yearId.value = value
  void load()
}

async function setHomeroom(row: Homeroom, value: string) {
  error.value = null
  const staffId = value ? Number(value) : null
  try {
    await teachersService.saveHomeroom(row.class_id, staffId)
    row.homeroom_staff_id = staffId
    row.homeroom = teachers.value.find((x) => x.id === staffId)?.name ?? null
    saved.value = row.class_id
    setTimeout(() => (saved.value = saved.value === row.class_id ? null : saved.value), 1500)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('teacherMgmt.saveFailed')
  }
}

const columns = computed(() => [
  { key: 'class', label: t('teacherMgmt.class') },
  { key: 'grade', label: t('teacherMgmt.gradeLevel') },
  { key: 'year', label: t('teacherMgmt.academicYear') },
  { key: 'homeroom', label: t('teacherMgmt.home.teacher') },
])

onMounted(async () => {
  try {
    const [list, opts] = await Promise.all([teachersService.list(), teachersService.options()])
    teachers.value = list
    years.value = opts.academic_years
    const current = opts.academic_years.find((y) => y.is_current)
    if (current) yearId.value = String(current.id)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('teacherMgmt.loadFailed')
  }
  await load()
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-48" :model-value="yearId" :options="yearOptions" @update:model-value="onYear" />
      <p class="text-sm text-neutral-500">{{ t('teacherMgmt.home.count', { done: assigned, total: rows.length }) }}</p>
      <BaseSpinner v-if="loading" class="h-4 w-4" />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="space-y-2 sm:hidden">
      <p v-if="!loading && rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('teacherMgmt.home.empty') }}</p>
      <div v-for="row in rows" :key="row.class_id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
        <p class="text-sm font-semibold text-neutral-800">{{ row.class }}</p>
        <p class="text-xs text-neutral-500">{{ [row.grade_level, row.academic_year].filter(Boolean).join(' · ') || '—' }}</p>
        <BaseSelect v-if="canManage" class="mt-2" :model-value="row.homeroom_staff_id ? String(row.homeroom_staff_id) : ''" :options="teacherOptions" @update:model-value="setHomeroom(row, $event)" />
        <p v-else class="mt-1 text-sm text-neutral-700">{{ row.homeroom || '—' }}</p>
        <p v-if="saved === row.class_id" class="mt-1 text-xs text-green-700">✓ {{ t('teacherMgmt.saved') }}</p>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="rows" row-key="class_id" :loading="loading" :empty-message="t('teacherMgmt.home.empty')">
        <template #cell-class="{ row }"><span class="font-medium text-neutral-800">{{ row.class }}</span></template>
        <template #cell-grade="{ row }">{{ row.grade_level || '—' }}</template>
        <template #cell-year="{ row }">{{ row.academic_year || '—' }}</template>
        <template #cell-homeroom="{ row }">
          <div class="flex items-center gap-2">
            <BaseSelect v-if="canManage" class="w-64" :model-value="row.homeroom_staff_id ? String(row.homeroom_staff_id) : ''" :options="teacherOptions" @update:model-value="setHomeroom(row as Homeroom, $event)" />
            <span v-else>{{ row.homeroom || '—' }}</span>
            <span v-if="saved === row.class_id" class="text-xs text-green-700">✓ {{ t('teacherMgmt.saved') }}</span>
          </div>
        </template>
      </DataTable>
    </div>
  </div>
</template>
