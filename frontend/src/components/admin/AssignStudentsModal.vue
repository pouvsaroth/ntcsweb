<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { studentRecordService, studentsService, type Student } from '@/services/students'
import { ApiRequestError } from '@/types/api'

/**
 * Class assignment (docs/academics/student-record.md): pick students and put
 * them into this class — no course package, no invoice (a Grade 1–12 class).
 * Students already in the class are skipped.
 */
const props = defineProps<{ modelValue: boolean; classId: number; className: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [message: string] }>()

const { t } = useI18n()

const search = ref('')
const results = ref<Student[]>([])
const picked = ref<Map<number, Student>>(new Map())
const searching = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined

async function find() {
  searching.value = true
  try {
    results.value = (await studentsService.list({ search: search.value.trim() || undefined, per_page: 30, filter: { status: 'active' } })).data
  } catch {
    results.value = []
  } finally {
    searching.value = false
  }
}

watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(find, 300)
})
watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    search.value = ''
    picked.value = new Map()
    error.value = null
    void find()
  },
)

function toggle(student: Student) {
  const next = new Map(picked.value)
  if (next.has(student.id)) next.delete(student.id)
  else next.set(student.id, student)
  picked.value = next
}
const pickedList = computed(() => [...picked.value.values()])

async function save() {
  saving.value = true
  error.value = null
  try {
    const r = await studentRecordService.assignToClass(props.classId, [...picked.value.keys()])
    emit('saved', t('studentRecord.assign.done', { added: r.added, skipped: r.skipped }))
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (e.errors ? (Object.values(e.errors)[0]?.[0] ?? e.message) : e.message) : t('studentRecord.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('studentRecord.assign.title', { class: className })" @update:model-value="emit('update:modelValue', $event)">
    <div class="space-y-3">
      <p class="text-sm text-neutral-500">{{ t('studentRecord.assign.hint') }}</p>
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>
      <input
        v-model="search"
        type="search"
        :placeholder="t('studentRecord.assign.search')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none"
      />
      <div v-if="pickedList.length" class="flex flex-wrap gap-1.5">
        <button v-for="s in pickedList" :key="s.id" type="button" class="rounded-full bg-primary-100 px-2.5 py-0.5 text-xs font-medium text-primary-800" @click="toggle(s)">{{ s.full_name }} ×</button>
      </div>
      <div class="max-h-72 overflow-y-auto rounded-lg border border-neutral-200">
        <div v-if="searching" class="flex justify-center py-6"><BaseSpinner /></div>
        <p v-else-if="results.length === 0" class="py-6 text-center text-sm text-neutral-500">{{ t('studentRecord.assign.none') }}</p>
        <label v-for="s in results" v-else :key="s.id" class="flex cursor-pointer items-center gap-3 border-b border-neutral-100 px-3 py-2 last:border-0 hover:bg-neutral-50">
          <input type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" :checked="picked.has(s.id)" @change="toggle(s)" />
          <span class="min-w-0 flex-1 truncate text-sm text-neutral-800">{{ s.full_name }}</span>
          <span class="shrink-0 text-xs text-neutral-500">{{ s.student_code }}</span>
        </label>
      </div>
    </div>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="picked.size === 0" @click="save">{{ t('studentRecord.assign.add', { count: picked.size }) }}</BaseButton>
    </template>
  </BaseModal>
</template>
