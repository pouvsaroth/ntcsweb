<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { adminUsersService } from '@/services/adminUsers'
import {
  projectTaskCommentsService,
  projectTaskHistoryService,
  type ProjectTaskComment,
  type ProjectTaskHistoryEntry,
} from '@/services/projectTaskComments'
import { projectTasksService, type ProjectTask, type ProjectTaskPriority } from '@/services/projects'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  /** Required when creating a new task; ignored when editing. */
  columnId?: number
  /** Present when editing; absent when adding a new task. */
  task?: ProjectTask | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [task: ProjectTask] }>()

const { t } = useI18n()
const auth = useAuthStore()

const isEditing = computed(() => props.task != null)

const assigneeOptions = ref<{ value: string; label: string }[]>([])

const priorityOptions = computed(() => [
  { value: 'low', label: t('admin.projects.priorityLow') },
  { value: 'medium', label: t('admin.projects.priorityMedium') },
  { value: 'high', label: t('admin.projects.priorityHigh') },
])

const form = reactive({
  title: '',
  description: '',
  priority: 'medium' as ProjectTaskPriority,
  due_date: '',
  assignee_id: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

async function loadAssignees() {
  const result = await adminUsersService.list({ page: 1, per_page: 100, sort: 'name' })
  assigneeOptions.value = result.data.map((user) => ({ value: String(user.id), label: user.name }))
}

// --- Comments ---

const comments = ref<ProjectTaskComment[]>([])
const commentsLoading = ref(false)
const newCommentBody = ref('')
const commentSubmitting = ref(false)
const commentError = ref<string | null>(null)

async function loadComments(taskId: number) {
  commentsLoading.value = true
  try {
    comments.value = await projectTaskCommentsService.list(taskId)
  } finally {
    commentsLoading.value = false
  }
}

async function addComment() {
  if (!props.task || !newCommentBody.value.trim()) return

  commentSubmitting.value = true
  commentError.value = null

  try {
    const comment = await projectTaskCommentsService.create(props.task.id, newCommentBody.value.trim())
    comments.value = [comment, ...comments.value]
    newCommentBody.value = ''
  } catch (e) {
    commentError.value = e instanceof ApiRequestError ? e.message : t('admin.projects.commentFailed')
  } finally {
    commentSubmitting.value = false
  }
}

async function removeComment(comment: ProjectTaskComment) {
  if (!window.confirm(t('admin.projects.deleteCommentConfirm'))) return

  try {
    await projectTaskCommentsService.remove(comment.id)
    comments.value = comments.value.filter((c) => c.id !== comment.id)
  } catch (e) {
    commentError.value = e instanceof ApiRequestError ? e.message : t('admin.projects.commentFailed')
  }
}

function canDeleteComment(comment: ProjectTaskComment): boolean {
  return comment.user_id === auth.user?.id || auth.can('projects.update')
}

// --- History ---

const history = ref<ProjectTaskHistoryEntry[]>([])
const historyLoading = ref(false)

async function loadHistory(taskId: number) {
  historyLoading.value = true
  try {
    history.value = await projectTaskHistoryService.list(taskId)
  } finally {
    historyLoading.value = false
  }
}

watch(
  () => [props.modelValue, props.task] as const,
  ([open, task]) => {
    if (!open) return

    form.title = props.task?.title ?? ''
    form.description = props.task?.description ?? ''
    form.priority = props.task?.priority ?? 'medium'
    form.due_date = props.task?.due_date ?? ''
    form.assignee_id = props.task?.assignee_id ? String(props.task.assignee_id) : ''
    errors.value = {}
    generalError.value = null

    comments.value = []
    history.value = []
    newCommentBody.value = ''
    commentError.value = null

    if (task) {
      void loadComments(task.id)
      void loadHistory(task.id)
    }
  },
  { immediate: true },
)

onMounted(() => loadAssignees())

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const input = {
      title: form.title,
      description: form.description || null,
      priority: form.priority,
      due_date: form.due_date || null,
      assignee_id: form.assignee_id ? Number(form.assignee_id) : null,
    }

    const saved = isEditing.value
      ? await projectTasksService.update(props.task!.id, input)
      : await projectTasksService.create(props.columnId!, input)

    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.projects.taskSaveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    size="lg"
    :title="isEditing ? t('admin.projects.editTaskTitle') : t('admin.projects.addTaskTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.title" required :label="t('admin.projects.taskTitle')" :error="errors.title?.[0]" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.projects.taskDescription') }}</label>
        <textarea
          v-model="form.description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
        <p v-if="errors.description?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.description[0] }}</p>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <BaseSelect
          v-model="form.priority"
          :options="priorityOptions"
          :label="t('admin.projects.taskPriority')"
          :error="errors.priority?.[0]"
        />
        <BaseInput v-model="form.due_date" type="date" :label="t('admin.projects.taskDueDate')" :error="errors.due_date?.[0]" />
      </div>

      <BaseSelect
        v-model="form.assignee_id"
        :options="assigneeOptions"
        :placeholder="t('admin.projects.taskUnassigned')"
        :label="t('admin.projects.taskAssignee')"
        :error="errors.assignee_id?.[0]"
      />
    </form>

    <template v-if="isEditing">
      <div class="mt-6 grid grid-cols-2 gap-6 border-t border-neutral-100 pt-5">
        <section>
          <h3 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('admin.projects.historyTitle') }}</h3>
          <div v-if="historyLoading" class="flex justify-center py-4"><BaseSpinner /></div>
          <p v-else-if="history.length === 0" class="text-sm text-neutral-400">{{ t('admin.projects.historyEmpty') }}</p>
          <ul v-else class="max-h-64 space-y-3 overflow-y-auto pr-1 text-sm">
            <li v-for="entry in history" :key="entry.id">
              <p class="text-neutral-700">{{ entry.description ?? entry.action }}</p>
              <p class="text-xs text-neutral-400">
                {{ entry.user?.name ?? t('admin.projects.historySystem') }} · {{ new Date(entry.created_at).toLocaleString() }}
              </p>
            </li>
          </ul>
        </section>

        <section>
          <h3 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('admin.projects.commentsTitle') }}</h3>

          <div class="mb-3 flex gap-2">
            <input
              v-model="newCommentBody"
              type="text"
              :placeholder="t('admin.projects.commentPlaceholder')"
              class="block w-full rounded-lg border border-neutral-300 px-3 py-1.5 text-sm text-neutral-900 shadow-sm placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
              @keydown.enter.prevent="addComment"
            />
            <BaseButton size="sm" :loading="commentSubmitting" :disabled="!newCommentBody.trim()" @click="addComment">
              {{ t('admin.projects.commentSubmit') }}
            </BaseButton>
          </div>

          <BaseAlert v-if="commentError" variant="danger" class="mb-2">{{ commentError }}</BaseAlert>

          <div v-if="commentsLoading" class="flex justify-center py-4"><BaseSpinner /></div>
          <p v-else-if="comments.length === 0" class="text-sm text-neutral-400">{{ t('admin.projects.commentsEmpty') }}</p>
          <ul v-else class="max-h-56 space-y-3 overflow-y-auto pr-1 text-sm">
            <li v-for="comment in comments" :key="comment.id" class="group rounded-lg bg-neutral-50 p-2.5">
              <div class="flex items-start justify-between gap-2">
                <p class="text-neutral-700">{{ comment.body }}</p>
                <button
                  v-if="canDeleteComment(comment)"
                  type="button"
                  class="hidden shrink-0 text-xs text-neutral-400 hover:text-danger-600 group-hover:inline"
                  @click="removeComment(comment)"
                >
                  {{ t('common.remove') }}
                </button>
              </div>
              <p class="mt-1 text-xs text-neutral-400">{{ comment.user_name }} · {{ new Date(comment.created_at).toLocaleString() }}</p>
            </li>
          </ul>
        </section>
      </div>
    </template>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
