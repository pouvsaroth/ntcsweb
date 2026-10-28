<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { type Classroom, classroomsService } from '@/services/classrooms'
import { type BulkTablesResult, type ClassroomTable, classroomTablesService } from '@/services/classroomTables'
import { ApiRequestError } from '@/types/api'

/**
 * Many tables in one save: a numbered range (prefix + 01..N, the order is
 * the number) or a copy of another room's tables.
 */
const props = defineProps<{ modelValue: boolean; classroom: Classroom }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [result: BulkTablesResult] }>()

const { t } = useI18n()

type Mode = 'range' | 'copy'
const mode = ref<Mode>('range')
const form = reactive({ prefix: '', count: '', start_number: '1', allow_many_students: false, remark: '', copy_from: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const rooms = ref<Classroom[]>([])
const sourceTables = ref<ClassroomTable[]>([])
const loadingSource = ref(false)

const roomOptions = computed(() =>
  rooms.value
    .filter((r) => r.id !== props.classroom.id)
    .map((r) => ({ value: String(r.id), label: r.code ? `${r.code} · ${r.name}` : r.name, hint: r.building?.name })),
)

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    mode.value = 'range'
    Object.assign(form, { prefix: '', count: '', start_number: '1', allow_many_students: false, remark: '', copy_from: '' })
    sourceTables.value = []
    errors.value = {}
    generalError.value = null
    if (rooms.value.length === 0) {
      try {
        rooms.value = await classroomsService.listAll()
      } catch {
        // The copy option just stays empty.
      }
    }
  },
  { immediate: true },
)

watch(
  () => form.copy_from,
  async (id) => {
    sourceTables.value = []
    if (!id) return
    loadingSource.value = true
    try {
      sourceTables.value = await classroomTablesService.listByClassroom(Number(id))
    } finally {
      loadingSource.value = false
    }
  },
)

// "NTS-T-01 … NTS-T-40": the names the range will make.
function nameOf(n: number, last: number): string {
  return form.prefix + String(n).padStart(Math.max(2, String(last).length), '0')
}
const rangePreview = computed(() => {
  const count = Number(form.count)
  const start = Number(form.start_number || 1)
  if (!Number.isInteger(count) || count < 1 || !Number.isInteger(start) || start < 0) return ''
  const last = start + count - 1
  return count === 1 ? nameOf(start, last) : `${nameOf(start, last)} … ${nameOf(last, last)}`
})

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null
  try {
    const result =
      mode.value === 'copy'
        ? await classroomTablesService.bulk({ classroom_id: props.classroom.id, copy_from_classroom_id: Number(form.copy_from) || null })
        : await classroomTablesService.bulk({
            classroom_id: props.classroom.id,
            prefix: form.prefix || null,
            count: form.count === '' ? null : Number(form.count),
            start_number: form.start_number === '' ? 1 : Number(form.start_number),
            allow_many_students: form.allow_many_students,
            remark: form.remark.trim() || null,
          })
    emit('saved', result)
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.classroomTables.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.classroomTables.createTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div class="grid grid-cols-2 gap-2 rounded-lg bg-neutral-100 p-1">
        <button
          v-for="m in (['range', 'copy'] as Mode[])"
          :key="m"
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-medium"
          :class="mode === m ? 'bg-white text-primary-700 shadow-sm' : 'text-neutral-600 hover:text-neutral-800'"
          @click="mode = m"
        >
          {{ m === 'range' ? t('admin.classroomTables.modeRange') : t('admin.classroomTables.modeCopy') }}
        </button>
      </div>

      <template v-if="mode === 'range'">
        <BaseInput v-model="form.prefix" :label="t('admin.classroomTables.prefix')" placeholder="NTS-T-" :hint="t('admin.classroomTables.prefixHint')" :error="errors.prefix?.[0]" />
        <div class="grid grid-cols-2 gap-3">
          <BaseInput v-model="form.count" type="number" required :label="t('admin.classroomTables.count')" placeholder="40" :error="errors.count?.[0]" />
          <BaseInput v-model="form.start_number" type="number" :label="t('admin.classroomTables.startNumber')" :error="errors.start_number?.[0]" />
        </div>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="form.allow_many_students" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.classroomTables.allowMany') }}
        </label>
        <BaseInput v-model="form.remark" :label="t('admin.classroomTables.remark')" :error="errors.remark?.[0]" />
        <p v-if="rangePreview" class="rounded-md bg-neutral-50 px-3 py-2 text-sm text-neutral-600">
          {{ t('admin.classroomTables.rangePreview', { count: Number(form.count), names: rangePreview }) }}
        </p>
      </template>

      <template v-else>
        <SearchableSelect
          v-model="form.copy_from"
          required
          :options="roomOptions"
          :label="t('admin.classroomTables.copyFrom')"
          :placeholder="t('admin.classroomTables.copyFromPlaceholder')"
          :error="errors.copy_from_classroom_id?.[0]"
        />
        <p v-if="form.copy_from && !loadingSource" class="rounded-md bg-neutral-50 px-3 py-2 text-sm text-neutral-600">
          {{
            sourceTables.length === 0
              ? t('admin.classroomTables.copyNone')
              : t('admin.classroomTables.copyPreview', { count: sourceTables.length, names: sourceTables.length === 1 ? sourceTables[0].name : `${sourceTables[0].name} … ${sourceTables[sourceTables.length - 1].name}` })
          }}
        </p>
      </template>

      <p class="text-xs text-neutral-500">{{ t('admin.classroomTables.skipNote') }}</p>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="mode === 'copy' && (!form.copy_from || sourceTables.length === 0)" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
