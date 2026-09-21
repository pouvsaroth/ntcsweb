<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import ProjectLabelPicker from '@/components/admin/ProjectLabelPicker.vue'
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
import {
  projectTaskAttachmentsService,
  projectTaskChecklistItemsService,
  projectTaskDependenciesService,
  projectTasksService,
  type ProjectMilestone,
  type ProjectTask,
  type ProjectTaskAttachment,
  type ProjectTaskPriority,
  type ProjectTaskSummary,
} from '@/services/projects'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

const props = defineProps<{
  modelValue: boolean
  /** Required when creating a new task; ignored when editing. */
  columnId?: number
  /** Present when editing; absent when adding a new task. */
  task?: ProjectTask | null
  /** This card's project's Sprints/Milestones, for the picker below. */
  milestones?: ProjectMilestone[]
  /** Every other card in the project, for the dependency picker — excludes this card itself. */
  candidateTasks?: ProjectTask[]
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

const milestoneOptions = computed(() => (props.milestones ?? []).map((m) => ({ value: String(m.id), label: m.name })))

const form = reactive({
  title: '',
  description: '',
  priority: 'medium' as ProjectTaskPriority,
  start_date: '',
  due_date: '',
  estimated_hours: '',
  project_milestone_id: '',
  assignee_id: '',
})

const labelIds = ref<number[]>([])

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

async function loadAssignees() {
  const result = await adminUsersService.list({ page: 1, per_page: 100, sort: 'name' })
  assigneeOptions.value = result.data.map((user) => ({ value: String(user.id), label: user.name }))
}

// --- Checklist/Subtasks ---
//
// Editing an existing card: every add/toggle/remove hits the API right
// away, same as Comments below. Adding a new card: the card doesn't exist
// yet for a checklist item to belong to, so items are staged locally
// (`id: null`) and flushed to the API right after the card itself is
// created — see submit().

interface ChecklistDraft {
  id: number | null
  key: string
  title: string
  is_completed: boolean
}

const checklist = ref<ChecklistDraft[]>([])
const newChecklistTitle = ref('')
const checklistError = ref<string | null>(null)

const checklistProgress = computed(() => ({
  completed: checklist.value.filter((i) => i.is_completed).length,
  total: checklist.value.length,
}))

async function addChecklistItem() {
  const title = newChecklistTitle.value.trim()
  if (!title) return

  checklistError.value = null

  if (isEditing.value && props.task) {
    try {
      const created = await projectTaskChecklistItemsService.create(props.task.id, title)
      checklist.value = [...checklist.value, { id: created.id, key: String(created.id), title: created.title, is_completed: created.is_completed }]
    } catch (e) {
      checklistError.value = e instanceof ApiRequestError ? e.message : t('admin.projects.checklistSaveFailed')
      return
    }
  } else {
    checklist.value = [...checklist.value, { id: null, key: crypto.randomUUID(), title, is_completed: false }]
  }

  newChecklistTitle.value = ''
}

async function toggleChecklistItem(item: ChecklistDraft) {
  const nextCompleted = !item.is_completed
  item.is_completed = nextCompleted

  if (item.id !== null) {
    try {
      await projectTaskChecklistItemsService.update(item.id, { is_completed: nextCompleted })
    } catch (e) {
      item.is_completed = !nextCompleted
      checklistError.value = e instanceof ApiRequestError ? e.message : t('admin.projects.checklistSaveFailed')
    }
  }
}

async function removeChecklistItem(item: ChecklistDraft) {
  checklist.value = checklist.value.filter((i) => i.key !== item.key)

  if (item.id !== null) {
    try {
      await projectTaskChecklistItemsService.remove(item.id)
    } catch (e) {
      checklistError.value = e instanceof ApiRequestError ? e.message : t('admin.projects.checklistSaveFailed')
    }
  }
}

// --- Attachments ---
//
// Same staged-until-created approach as the checklist above: a picked file
// can't be uploaded against a card that doesn't have an id yet.

interface AttachmentDraft {
  id: number | null
  key: string
  file?: File
  file_name: string
  mime_type: string | null
  url: string | null
}

const attachments = ref<AttachmentDraft[]>([])
const attachmentUploading = ref(false)
const attachmentError = ref<string | null>(null)

async function loadAttachments(taskId: number) {
  const list = await projectTaskAttachmentsService.list(taskId)
  attachments.value = list.map((a) => ({ id: a.id, key: String(a.id), file_name: a.file_name, mime_type: a.mime_type, url: a.url }))
}

async function onAttachmentPicked(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  ;(event.target as HTMLInputElement).value = ''
  if (!file) return

  attachmentError.value = null

  if (isEditing.value && props.task) {
    attachmentUploading.value = true
    try {
      const uploaded = await projectTaskAttachmentsService.upload(props.task.id, file)
      attachments.value = [...attachments.value, { id: uploaded.id, key: String(uploaded.id), file_name: uploaded.file_name, mime_type: uploaded.mime_type, url: uploaded.url }]
    } catch (e) {
      attachmentError.value = e instanceof ApiRequestError ? e.message : t('admin.projects.attachmentSaveFailed')
    } finally {
      attachmentUploading.value = false
    }
  } else {
    attachments.value = [...attachments.value, { id: null, key: crypto.randomUUID(), file, file_name: file.name, mime_type: file.type || null, url: null }]
  }
}

async function removeAttachment(item: AttachmentDraft) {
  attachments.value = attachments.value.filter((a) => a.key !== item.key)

  if (item.id !== null && props.task) {
    try {
      await projectTaskAttachmentsService.remove(props.task.id, item.id)
    } catch (e) {
      attachmentError.value = e instanceof ApiRequestError ? e.message : t('admin.projects.attachmentSaveFailed')
    }
  }
}

// --- Dependencies ---
//
// Editing an existing card: adding/removing a dependency hits the API right
// away. Adding a new card: the link is staged locally and created right
// after the card itself is, same reasoning as checklist/attachments above.

const dependencies = ref<ProjectTaskSummary[]>([])
const dependencyPickerId = ref('')
const dependencyError = ref<string | null>(null)

const dependencyOptions = computed(() => {
  const selectedIds = new Set(dependencies.value.map((d) => d.id))
  return (props.candidateTasks ?? [])
    .filter((candidate) => candidate.id !== props.task?.id && !selectedIds.has(candidate.id))
    .map((candidate) => ({ value: String(candidate.id), label: candidate.title }))
})

async function addDependency() {
  const id = Number(dependencyPickerId.value)
  if (!id) return

  dependencyError.value = null

  if (isEditing.value && props.task) {
    try {
      const updated = await projectTaskDependenciesService.add(props.task.id, id)
      dependencies.value = updated
    } catch (e) {
      dependencyError.value = e instanceof ApiRequestError ? e.message : t('admin.projects.dependencySaveFailed')
      return
    }
  } else {
    const candidate = (props.candidateTasks ?? []).find((c) => c.id === id)
    if (candidate) dependencies.value = [...dependencies.value, { id: candidate.id, title: candidate.title, project_column_id: candidate.project_column_id, priority: candidate.priority }]
  }

  dependencyPickerId.value = ''
}

async function removeDependency(dependency: ProjectTaskSummary) {
  dependencies.value = dependencies.value.filter((d) => d.id !== dependency.id)

  if (isEditing.value && props.task) {
    try {
      await projectTaskDependenciesService.remove(props.task.id, dependency.id)
    } catch (e) {
      dependencyError.value = e instanceof ApiRequestError ? e.message : t('admin.projects.dependencySaveFailed')
    }
  }
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
    form.start_date = props.task?.start_date ?? ''
    form.due_date = props.task?.due_date ?? ''
    form.estimated_hours = props.task?.estimated_hours != null ? String(props.task.estimated_hours) : ''
    form.project_milestone_id = props.task?.project_milestone_id ? String(props.task.project_milestone_id) : ''
    form.assignee_id = props.task?.assignee_id ? String(props.task.assignee_id) : ''
    errors.value = {}
    generalError.value = null

    labelIds.value = props.task?.labels.map((l) => l.id) ?? []

    checklist.value = (props.task?.checklist_items ?? []).map((item) => ({ id: item.id, key: String(item.id), title: item.title, is_completed: item.is_completed }))
    newChecklistTitle.value = ''
    checklistError.value = null

    attachments.value = []
    attachmentError.value = null

    dependencies.value = props.task?.dependencies ?? []
    dependencyPickerId.value = ''
    dependencyError.value = null

    comments.value = []
    history.value = []
    newCommentBody.value = ''
    commentError.value = null

    if (task) {
      void loadComments(task.id)
      void loadHistory(task.id)
      void loadAttachments(task.id)
    }
  },
  { immediate: true },
)

onMounted(() => loadAssignees())

/** Pushes every staged checklist item/attachment/dependency to the API right after a new card is created — see those sections' docblocks above. */
async function flushStagedChildren(task: ProjectTask): Promise<ProjectTask> {
  const createdChecklistItems = []
  for (const draft of checklist.value) {
    const created = await projectTaskChecklistItemsService.create(task.id, draft.title)
    if (draft.is_completed) {
      await projectTaskChecklistItemsService.update(created.id, { is_completed: true })
      created.is_completed = true
    }
    createdChecklistItems.push(created)
  }

  const uploadedAttachments: ProjectTaskAttachment[] = []
  for (const draft of attachments.value) {
    if (draft.file) uploadedAttachments.push(await projectTaskAttachmentsService.upload(task.id, draft.file))
  }

  let finalDependencies: ProjectTaskSummary[] = []
  for (const dependency of dependencies.value) {
    finalDependencies = await projectTaskDependenciesService.add(task.id, dependency.id)
  }

  return {
    ...task,
    checklist_items: createdChecklistItems,
    checklist_progress: { completed: createdChecklistItems.filter((i) => i.is_completed).length, total: createdChecklistItems.length },
    attachments: uploadedAttachments,
    dependencies: finalDependencies.length > 0 ? finalDependencies : dependencies.value,
  }
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const input = {
      title: form.title,
      description: form.description || null,
      priority: form.priority,
      start_date: form.start_date || null,
      due_date: form.due_date || null,
      estimated_hours: form.estimated_hours !== '' ? Number(form.estimated_hours) : null,
      project_milestone_id: form.project_milestone_id ? Number(form.project_milestone_id) : null,
      assignee_id: form.assignee_id ? Number(form.assignee_id) : null,
      label_ids: labelIds.value,
    }

    let saved = isEditing.value
      ? await projectTasksService.update(props.task!.id, input)
      : await projectTasksService.create(props.columnId!, input)

    if (!isEditing.value) {
      saved = await flushStagedChildren(saved)
    }

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
        <BaseSelect
          v-model="form.assignee_id"
          :options="assigneeOptions"
          :placeholder="t('admin.projects.taskUnassigned')"
          :label="t('admin.projects.taskAssignee')"
          :error="errors.assignee_id?.[0]"
        />
      </div>

      <div class="grid grid-cols-2 gap-3">
        <BaseInput v-model="form.start_date" type="date" :label="t('admin.projects.taskStartDate')" :error="errors.start_date?.[0]" />
        <BaseInput v-model="form.due_date" type="date" :label="t('admin.projects.taskDueDate')" :error="errors.due_date?.[0]" />
      </div>

      <ProjectLabelPicker v-model="labelIds" />

      <div class="grid grid-cols-2 gap-3">
        <BaseInput
          :model-value="form.estimated_hours"
          type="number"
          :label="t('admin.projects.taskEstimatedHours')"
          :error="errors.estimated_hours?.[0]"
          @update:model-value="form.estimated_hours = $event === '' ? '' : String(Math.max(0, Number($event) || 0))"
        />
        <BaseSelect
          v-model="form.project_milestone_id"
          :options="milestoneOptions"
          :placeholder="t('admin.projects.taskNoMilestone')"
          :label="t('admin.projects.taskMilestone')"
          :error="errors.project_milestone_id?.[0]"
        />
      </div>

      <!-- Checklist/Subtasks -->
      <div>
        <div class="mb-1.5 flex items-center justify-between">
          <label class="block text-sm font-medium text-neutral-700">{{ t('admin.projects.checklistTitle') }}</label>
          <span v-if="checklistProgress.total > 0" class="text-xs text-neutral-400">
            {{ t('admin.projects.checklistProgress', { completed: checklistProgress.completed, total: checklistProgress.total }) }}
          </span>
        </div>

        <BaseAlert v-if="checklistError" variant="danger" class="mb-2">{{ checklistError }}</BaseAlert>

        <ul v-if="checklist.length > 0" class="mb-2 space-y-1.5">
          <li v-for="item in checklist" :key="item.key" class="group flex items-center gap-2">
            <input
              type="checkbox"
              :checked="item.is_completed"
              class="h-4 w-4 shrink-0 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              @change="toggleChecklistItem(item)"
            />
            <span class="flex-1 text-sm text-neutral-700" :class="item.is_completed ? 'text-neutral-400 line-through' : ''">{{ item.title }}</span>
            <button
              type="button"
              class="hidden shrink-0 text-xs text-neutral-400 hover:text-danger-600 group-hover:inline"
              @click="removeChecklistItem(item)"
            >
              {{ t('common.remove') }}
            </button>
          </li>
        </ul>

        <div class="flex gap-2">
          <input
            v-model="newChecklistTitle"
            type="text"
            :placeholder="t('admin.projects.addChecklistItemPlaceholder')"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-1.5 text-sm text-neutral-900 shadow-sm placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
            @keydown.enter.prevent="addChecklistItem"
          />
          <BaseButton type="button" size="sm" variant="outline" :disabled="!newChecklistTitle.trim()" @click="addChecklistItem">
            {{ t('admin.projects.addChecklistItem') }}
          </BaseButton>
        </div>
      </div>

      <!-- Attachments -->
      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.projects.attachmentsTitle') }}</label>

        <BaseAlert v-if="attachmentError" variant="danger" class="mb-2">{{ attachmentError }}</BaseAlert>

        <ul v-if="attachments.length > 0" class="mb-2 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          <li v-for="attachment in attachments" :key="attachment.key" class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
            <a v-if="attachment.url" :href="attachment.url" target="_blank" rel="noopener noreferrer" class="truncate text-primary-700 hover:underline">
              {{ attachment.file_name }}
            </a>
            <span v-else class="truncate text-neutral-600">{{ attachment.file_name }}</span>
            <button type="button" class="shrink-0 text-xs text-neutral-400 hover:text-danger-600" @click="removeAttachment(attachment)">
              {{ t('common.remove') }}
            </button>
          </li>
        </ul>

        <label class="inline-flex cursor-pointer items-center gap-1.5 text-sm font-medium text-primary-700 hover:text-primary-800">
          <BaseSpinner v-if="attachmentUploading" size="sm" />
          <span v-else>+ {{ t('admin.projects.addAttachment') }}</span>
          <input type="file" class="hidden" :disabled="attachmentUploading" @change="onAttachmentPicked" />
        </label>
      </div>

      <!-- Dependencies -->
      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.projects.dependenciesTitle') }}</label>

        <BaseAlert v-if="dependencyError" variant="danger" class="mb-2">{{ dependencyError }}</BaseAlert>

        <ul v-if="dependencies.length > 0" class="mb-2 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          <li v-for="dependency in dependencies" :key="dependency.id" class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
            <span class="truncate text-neutral-700">{{ dependency.title }}</span>
            <button type="button" class="shrink-0 text-xs text-neutral-400 hover:text-danger-600" @click="removeDependency(dependency)">
              {{ t('common.remove') }}
            </button>
          </li>
        </ul>

        <div class="flex gap-2">
          <BaseSelect
            v-model="dependencyPickerId"
            :options="dependencyOptions"
            :placeholder="t('admin.projects.dependencyPickerPlaceholder')"
          />
          <BaseButton type="button" size="sm" variant="outline" :disabled="!dependencyPickerId" @click="addDependency">
            {{ t('admin.projects.addDependency') }}
          </BaseButton>
        </div>
      </div>
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
                {{ entry.user?.name ?? t('admin.projects.historySystem') }} · {{ formatDateTime(entry.created_at) }}
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
              <p class="mt-1 text-xs text-neutral-400">{{ comment.user_name }} · {{ formatDateTime(comment.created_at) }}</p>
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
