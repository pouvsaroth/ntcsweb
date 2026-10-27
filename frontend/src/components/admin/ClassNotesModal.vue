<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { enrollmentsService, type Enrollment } from '@/services/enrollments'
import { ApiRequestError } from '@/types/api'

/**
 * Class > Students > Note: every student on the roster (table, name, book)
 * with their note, edited together and saved in one go — like Add score.
 * Only the notes that changed are sent.
 */
const props = defineProps<{ modelValue: boolean; enrollments: Enrollment[] }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [count: number] }>()

const { t } = useI18n()

interface Draft {
  enrollment: Enrollment
  note: string
}

const drafts = ref<Draft[]>([])
const saving = ref(false)
const saveError = ref<string | null>(null)

watch(
  () => props.modelValue,
  (open) => {
    saveError.value = null
    if (open) drafts.value = props.enrollments.map((enrollment) => ({ enrollment, note: enrollment.note ?? '' }))
  },
)

async function save() {
  const changed = drafts.value.filter((draft) => draft.note.trim() !== (draft.enrollment.note ?? '').trim())
  if (changed.length === 0) {
    emit('update:modelValue', false)
    return
  }

  saving.value = true
  saveError.value = null
  try {
    await enrollmentsService.saveNotes(changed.map((draft) => ({ enrollment_id: draft.enrollment.id, note: draft.note.trim() || null })))
    emit('saved', changed.length)
    emit('update:modelValue', false)
  } catch (error) {
    saveError.value = error instanceof ApiRequestError ? error.message : t('admin.classStudents.notesFailed')
  } finally {
    saving.value = false
  }
}

const inputClass =
  'w-full rounded-lg border border-neutral-300 px-3 py-1.5 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200'
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.classStudents.notesTitle')" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <BaseAlert v-if="saveError" variant="danger" class="mb-4">{{ saveError }}</BaseAlert>

    <p v-if="drafts.length === 0" class="py-8 text-center text-sm text-neutral-400">{{ t('admin.classStudents.emptyMessage') }}</p>

    <template v-else>
      <!-- Phone: one card per student. -->
      <div class="max-h-[65vh] space-y-2 overflow-y-auto sm:hidden">
        <div v-for="draft in drafts" :key="draft.enrollment.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3">
          <p class="text-sm font-semibold text-neutral-800">{{ draft.enrollment.student.full_name }}</p>
          <p class="mb-2 text-xs text-neutral-500">
            {{ t('admin.classStudents.columnTable') }}: {{ draft.enrollment.table?.name ?? '—' }} · {{ draft.enrollment.course_package?.name ?? '—' }}
          </p>
          <textarea v-model="draft.note" rows="2" maxlength="1000" :placeholder="t('admin.classStudents.notePlaceholder')" :class="inputClass" />
        </div>
      </div>

      <!-- Desktop: a table. -->
      <div class="hidden max-h-[65vh] overflow-y-auto rounded-lg border border-neutral-200 sm:block">
        <table class="w-full text-sm">
          <thead class="sticky top-0 z-10 bg-neutral-50 text-left text-xs font-medium uppercase text-neutral-500">
            <tr>
              <th class="w-10 px-3 py-2">#</th>
              <th class="w-20 px-3 py-2">{{ t('admin.classStudents.columnTable') }}</th>
              <th class="px-3 py-2">{{ t('admin.classStudents.columnStudent') }}</th>
              <th class="px-3 py-2">{{ t('admin.classStudents.columnBook') }}</th>
              <th class="w-[40%] px-3 py-2">{{ t('admin.classStudents.columnNote') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(draft, index) in drafts" :key="draft.enrollment.id" class="align-top">
              <td class="px-3 py-2 text-neutral-500">{{ index + 1 }}</td>
              <td class="px-3 py-2">{{ draft.enrollment.table?.name ?? '—' }}</td>
              <td class="px-3 py-2 font-medium text-neutral-800">{{ draft.enrollment.student.full_name }}</td>
              <td class="px-3 py-2 text-neutral-600">{{ draft.enrollment.course_package?.name ?? '—' }}</td>
              <td class="px-3 py-1.5">
                <textarea v-model="draft.note" rows="1" maxlength="1000" :placeholder="t('admin.classStudents.notePlaceholder')" :class="inputClass" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :disabled="drafts.length === 0" :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
