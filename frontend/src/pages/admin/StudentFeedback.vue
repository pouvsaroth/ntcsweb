<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import type { StudentFeedback, StudentFeedbackStatus } from '@/services/myStudentFeedback'
import { studentFeedbackService } from '@/services/studentFeedback'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/**
 * The admin/staff queue for every student's request or comment about the
 * school or a teacher — see MyFeedback.vue for the student-facing side of
 * this same thread. Gated by `student-feedback.view`/`.reply`, same as the
 * sidebar nav entry (see adminNav.ts's Communication group).
 */
const { t } = useI18n()
const auth = useAuthStore()

const rows = ref<StudentFeedback[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const activeTab = ref<StudentFeedbackStatus>('open')

const canReply = computed(() => auth.can('student-feedback.reply'))

const tabs: { key: StudentFeedbackStatus; labelKey: string }[] = [
  { key: 'open', labelKey: 'admin.studentFeedback.tabOpen' },
  { key: 'replied', labelKey: 'admin.studentFeedback.tabReplied' },
]

const counts = computed(() => ({
  open: rows.value.filter((r) => r.status === 'open').length,
  replied: rows.value.filter((r) => r.status === 'replied').length,
}))

const visibleRows = computed(() => rows.value.filter((r) => r.status === activeTab.value))

const columns = [
  { key: 'date', label: t('admin.studentFeedback.columnDate') },
  { key: 'student', label: t('admin.studentFeedback.columnStudent') },
  { key: 'subject', label: t('admin.studentFeedback.columnSubject') },
  { key: 'type', label: t('admin.studentFeedback.columnType') },
  { key: 'status', label: t('admin.studentFeedback.columnStatus') },
]

const statusVariant: Record<StudentFeedbackStatus, 'warning' | 'success'> = {
  open: 'warning',
  replied: 'success',
}

async function load() {
  loading.value = true
  error.value = null

  try {
    const result = await studentFeedbackService.list()
    rows.value = result.data
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.studentFeedback.loadFailed')
  } finally {
    loading.value = false
  }
}

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
    const updated = await studentFeedbackService.reply(detail.value.id, replyBody.value.trim())
    detail.value = updated
    replyBody.value = ''
    const index = rows.value.findIndex((r) => r.id === updated.id)
    if (index !== -1) rows.value[index] = updated
  } catch (e) {
    replyError.value = e instanceof ApiRequestError ? e.message : t('admin.studentFeedback.replyFailed')
  } finally {
    replySubmitting.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.studentFeedback.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.studentFeedback.subtitle') }}</p>
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
      :title="t('admin.studentFeedback.emptyTitle')"
      :message="t('admin.studentFeedback.emptyMessage')"
    />

    <DataTable v-else :columns="columns" :rows="visibleRows" row-key="id">
      <template #cell-date="{ row }">{{ new Date(row.created_at).toLocaleDateString() }}</template>
      <template #cell-student="{ row }">{{ row.student?.name ?? '—' }}</template>
      <template #cell-subject="{ row }">
        <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="openDetail(row)">
          {{ row.subject }}
        </button>
      </template>
      <template #cell-type="{ row }">
        {{ row.type === 'request' ? t('admin.studentFeedback.typeRequest') : t('admin.studentFeedback.typeComment') }}
        <span class="text-neutral-400">— {{ row.topic === 'teacher' ? (row.teacher?.name ?? '—') : t('admin.studentFeedback.topicSchool') }}</span>
      </template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant[row.status]">
          {{ row.status === 'open' ? t('admin.studentFeedback.statusOpen') : t('admin.studentFeedback.statusReplied') }}
        </BaseBadge>
      </template>
    </DataTable>

    <BaseModal :model-value="detail !== null" :title="detail?.subject" size="lg" @update:model-value="detail = null">
      <template v-if="detail">
        <dl class="mb-4 grid gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('admin.studentFeedback.columnStudent') }}</dt><dd class="font-medium text-neutral-900">{{ detail.student?.name ?? '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.studentFeedback.columnType') }}</dt><dd class="font-medium text-neutral-900">
            {{ detail.type === 'request' ? t('admin.studentFeedback.typeRequest') : t('admin.studentFeedback.typeComment') }}
            — {{ detail.topic === 'teacher' ? (detail.teacher?.name ?? '—') : t('admin.studentFeedback.topicSchool') }}
          </dd></div>
        </dl>
        <div class="mb-4 rounded-lg bg-neutral-50 p-3 text-sm text-neutral-700">{{ detail.message }}</div>

        <p class="mb-2 text-sm font-medium text-neutral-700">{{ t('admin.studentFeedback.replyThreadTitle') }}</p>
        <div v-if="detail.replies.length === 0" class="mb-4 text-sm text-neutral-400">—</div>
        <ul v-else class="mb-4 space-y-3">
          <li v-for="reply in detail.replies" :key="reply.id" class="rounded-lg border border-neutral-100 p-3">
            <div class="mb-1 flex items-center justify-between text-xs text-neutral-500">
              <span class="font-medium">{{ reply.user?.name ?? '—' }}</span>
              <span>{{ new Date(reply.created_at).toLocaleString() }}</span>
            </div>
            <p class="text-sm text-neutral-800">{{ reply.body }}</p>
          </li>
        </ul>

        <template v-if="canReply">
          <BaseAlert v-if="replyError" variant="danger" class="mb-3">{{ replyError }}</BaseAlert>
          <form class="flex gap-2" @submit.prevent="sendReply">
            <textarea
              v-model="replyBody"
              rows="2"
              :placeholder="t('admin.studentFeedback.replyPlaceholder')"
              class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
            />
            <BaseButton :loading="replySubmitting" :disabled="!replyBody.trim()" @click="sendReply">
              {{ t('admin.studentFeedback.replySubmit') }}
            </BaseButton>
          </form>
        </template>
      </template>

      <template #footer>
        <BaseButton variant="outline" @click="detail = null">{{ t('common.close') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
