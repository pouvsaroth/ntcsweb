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
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import {
  myStudentFeedbackService,
  type StudentFeedback,
  type StudentFeedbackStatus,
  type StudentFeedbackTeacherOption,
  type StudentFeedbackTopic,
  type StudentFeedbackType,
} from '@/services/myStudentFeedback'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/**
 * "My Request" / "Comment" under the student account menu — every request
 * or comment the student has sent about the school or a teacher, with
 * whatever reply a signed-in staff/admin has posted (see
 * StudentFeedback.vue, the admin-side queue). Both dropdown entries open
 * this same page — the "New" form is where a student picks Request vs
 * Comment, not two separate flows for what's really one inbox+thread.
 */
const { t } = useI18n()
const auth = useAuthStore()

const rows = ref<StudentFeedback[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const activeTab = ref<StudentFeedbackStatus>('open')

const tabs: { key: StudentFeedbackStatus; labelKey: string }[] = [
  { key: 'open', labelKey: 'admin.myFeedback.tabOpen' },
  { key: 'replied', labelKey: 'admin.myFeedback.tabReplied' },
]

const counts = computed(() => ({
  open: rows.value.filter((r) => r.status === 'open').length,
  replied: rows.value.filter((r) => r.status === 'replied').length,
}))

const visibleRows = computed(() => rows.value.filter((r) => r.status === activeTab.value))

const columns = [
  { key: 'date', label: t('admin.myFeedback.columnDate') },
  { key: 'subject', label: t('admin.myFeedback.columnSubject') },
  { key: 'type', label: t('admin.myFeedback.columnType') },
  { key: 'status', label: t('admin.myFeedback.columnStatus') },
]

const statusVariant: Record<StudentFeedbackStatus, 'warning' | 'success'> = {
  open: 'warning',
  replied: 'success',
}

async function load() {
  loading.value = true
  error.value = null

  try {
    const result = await myStudentFeedbackService.list()
    rows.value = result.data
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.myFeedback.loadFailed')
  } finally {
    loading.value = false
  }
}

// --- New feedback ---

const createOpen = ref(false)
const createForm = reactive<{ type: StudentFeedbackType; topic: StudentFeedbackTopic; teacher_id: string; subject: string; message: string }>({
  type: 'comment',
  topic: 'school',
  teacher_id: '',
  subject: '',
  message: '',
})
const teachers = ref<StudentFeedbackTeacherOption[]>([])
const teachersLoading = ref(false)
const teachersError = ref<string | null>(null)
const createErrors = ref<Record<string, string[]>>({})
const createGeneralError = ref<string | null>(null)
const creating = ref(false)

const typeOptions = computed(() => [
  { value: 'request', label: t('admin.myFeedback.typeRequest') },
  { value: 'comment', label: t('admin.myFeedback.typeComment') },
])

const topicOptions = computed(() => [
  { value: 'school', label: t('admin.myFeedback.topicSchool') },
  { value: 'teacher', label: t('admin.myFeedback.topicTeacher') },
])

const teacherOptions = computed(() => teachers.value.map((teacher) => ({ value: String(teacher.id), label: teacher.name })))

async function openCreate() {
  createForm.type = 'comment'
  createForm.topic = 'school'
  createForm.teacher_id = ''
  createForm.subject = ''
  createForm.message = ''
  createErrors.value = {}
  createGeneralError.value = null
  createOpen.value = true

  if (teachers.value.length === 0 && !teachersLoading.value) {
    teachersLoading.value = true
    teachersError.value = null
    try {
      teachers.value = await myStudentFeedbackService.teachers()
    } catch (e) {
      teachersError.value = e instanceof ApiRequestError ? e.message : t('admin.myFeedback.teachersLoadFailed')
    } finally {
      teachersLoading.value = false
    }
  }
}

async function submitCreate() {
  creating.value = true
  createErrors.value = {}
  createGeneralError.value = null

  try {
    await myStudentFeedbackService.submit({
      type: createForm.type,
      topic: createForm.topic,
      teacher_id: createForm.topic === 'teacher' && createForm.teacher_id ? Number(createForm.teacher_id) : null,
      subject: createForm.subject,
      message: createForm.message,
    })
    createOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) {
      createErrors.value = e.errors
    } else {
      createGeneralError.value = e instanceof ApiRequestError ? e.message : t('admin.myFeedback.submitFailed')
    }
  } finally {
    creating.value = false
  }
}

// --- Thread / reply ---

const detail = ref<StudentFeedback | null>(null)
const replyBody = ref('')
const replySubmitting = ref(false)
const replyError = ref<string | null>(null)

function openDetail(row: StudentFeedback) {
  detail.value = row
  replyBody.value = ''
  replyError.value = null
}

async function sendReply() {
  if (!detail.value || !replyBody.value.trim()) return

  replySubmitting.value = true
  replyError.value = null

  try {
    const updated = await myStudentFeedbackService.reply(detail.value.id, replyBody.value.trim())
    detail.value = updated
    replyBody.value = ''
    const index = rows.value.findIndex((r) => r.id === updated.id)
    if (index !== -1) rows.value[index] = updated
  } catch (e) {
    replyError.value = e instanceof ApiRequestError ? e.message : t('admin.myFeedback.replyFailed')
  } finally {
    replySubmitting.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.myFeedback.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.myFeedback.subtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.myFeedback.newButton') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-4 flex gap-1 border-b border-neutral-200">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        class="border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === tab.key ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
        @click="activeTab = tab.key"
      >
        {{ t(tab.labelKey) }} ({{ counts[tab.key] }})
      </button>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <EmptyState
      v-else-if="visibleRows.length === 0"
      :title="t('admin.myFeedback.emptyTitle')"
      :message="t('admin.myFeedback.emptyMessage')"
    />

    <DataTable v-else :columns="columns" :rows="visibleRows" row-key="id">
      <template #cell-date="{ row }">{{ new Date(row.created_at).toLocaleDateString() }}</template>
      <template #cell-subject="{ row }">
        <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="openDetail(row)">
          {{ row.subject }}
        </button>
      </template>
      <template #cell-type="{ row }">
        {{ row.type === 'request' ? t('admin.myFeedback.typeRequest') : t('admin.myFeedback.typeComment') }}
        <span class="text-neutral-400">— {{ row.topic === 'teacher' ? (row.teacher?.name ?? t('admin.myFeedback.topicTeacher')) : t('admin.myFeedback.topicSchool') }}</span>
      </template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant[row.status]">
          {{ row.status === 'open' ? t('admin.myFeedback.statusOpen') : t('admin.myFeedback.statusReplied') }}
        </BaseBadge>
      </template>
    </DataTable>

    <!-- New request/comment -->
    <BaseModal v-model="createOpen" :title="t('admin.myFeedback.createTitle')">
      <form class="space-y-4" @submit.prevent="submitCreate">
        <BaseAlert v-if="createGeneralError" variant="danger">{{ createGeneralError }}</BaseAlert>

        <BaseSelect v-model="createForm.type" :label="t('admin.myFeedback.typeLabel')" :options="typeOptions" required />
        <BaseSelect v-model="createForm.topic" :label="t('admin.myFeedback.topicLabel')" :options="topicOptions" required />

        <template v-if="createForm.topic === 'teacher'">
          <BaseAlert v-if="teachersError" variant="danger">{{ teachersError }}</BaseAlert>
          <BaseSelect
            v-model="createForm.teacher_id"
            :label="t('admin.myFeedback.teacherLabel')"
            :placeholder="t('admin.myFeedback.selectTeacher')"
            :options="teacherOptions"
            :disabled="teachersLoading"
            required
            :error="createErrors.teacher_id?.[0]"
          />
        </template>

        <BaseInput v-model="createForm.subject" :label="t('admin.myFeedback.subjectLabel')" required :error="createErrors.subject?.[0]" />

        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">
            {{ t('admin.myFeedback.messageLabel') }} <span class="text-danger-600">*</span>
          </label>
          <textarea
            v-model="createForm.message"
            rows="4"
            required
            :placeholder="t('admin.myFeedback.messagePlaceholder')"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          />
          <p v-if="createErrors.message?.[0]" class="mt-1.5 text-sm text-danger-600">{{ createErrors.message[0] }}</p>
        </div>
      </form>

      <template #footer>
        <BaseButton variant="outline" @click="createOpen = false">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :loading="creating" @click="submitCreate">{{ t('admin.myFeedback.submit') }}</BaseButton>
      </template>
    </BaseModal>

    <!-- Thread -->
    <BaseModal :model-value="detail !== null" :title="detail?.subject" size="lg" @update:model-value="detail = null">
      <template v-if="detail">
        <div class="mb-4 rounded-lg bg-neutral-50 p-3 text-sm text-neutral-700">{{ detail.message }}</div>

        <p class="mb-2 text-sm font-medium text-neutral-700">{{ t('admin.myFeedback.replyThreadTitle') }}</p>
        <div v-if="detail.replies.length === 0" class="mb-4 text-sm text-neutral-400">—</div>
        <ul v-else class="mb-4 space-y-3">
          <li v-for="reply in detail.replies" :key="reply.id" class="rounded-lg border border-neutral-100 p-3">
            <div class="mb-1 flex items-center justify-between text-xs text-neutral-500">
              <span class="font-medium">{{ reply.user?.id === auth.user?.id ? t('common.you') : (reply.user?.name ?? '—') }}</span>
              <span>{{ new Date(reply.created_at).toLocaleString() }}</span>
            </div>
            <p class="text-sm text-neutral-800">{{ reply.body }}</p>
          </li>
        </ul>

        <BaseAlert v-if="replyError" variant="danger" class="mb-3">{{ replyError }}</BaseAlert>
        <form class="flex gap-2" @submit.prevent="sendReply">
          <textarea
            v-model="replyBody"
            rows="2"
            :placeholder="t('admin.myFeedback.replyPlaceholder')"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          />
          <BaseButton :loading="replySubmitting" :disabled="!replyBody.trim()" @click="sendReply">
            {{ t('admin.myFeedback.replySubmit') }}
          </BaseButton>
        </form>
      </template>

      <template #footer>
        <BaseButton variant="outline" @click="detail = null">{{ t('common.close') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
