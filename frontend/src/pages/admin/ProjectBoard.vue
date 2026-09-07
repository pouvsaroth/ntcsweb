<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import ProjectColumnFormModal from '@/components/admin/ProjectColumnFormModal.vue'
import ProjectFormModal from '@/components/admin/ProjectFormModal.vue'
import ProjectTaskFormModal from '@/components/admin/ProjectTaskFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { projectTaskCommentsService } from '@/services/projectTaskComments'
import { projectColumnsService, projectsService, projectTasksService, type Project, type ProjectColumn, type ProjectTask } from '@/services/projects'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/**
 * The Kanban board for one project. Drag-and-drop uses plain HTML5 DnD
 * (draggable + dragstart/dragover/drop) rather than a library — the
 * reordering math is the only non-trivial part, and it's small enough to
 * keep inline: see moveTask()'s index calculation, which counts only the
 * *other* tasks before the drop point so it matches what
 * ProjectTaskService::move() expects on the backend (a position among
 * siblings with the moved task already removed).
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const projectId = computed(() => Number(route.params.id))
const project = ref<Project | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const actionError = ref<string | null>(null)

const canUpdate = computed(() => auth.can('projects.update'))
const canDelete = computed(() => auth.can('projects.delete'))

const priorityVariant: Record<string, 'neutral' | 'warning' | 'danger'> = {
  low: 'neutral',
  medium: 'warning',
  high: 'danger',
}

async function load() {
  loading.value = true
  error.value = null

  try {
    project.value = await projectsService.get(projectId.value)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.projects.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(() => load())

// --- Project edit/delete ---

const editProjectOpen = ref(false)

function onProjectSaved(updated: Project) {
  if (project.value) project.value = { ...project.value, name: updated.name, description: updated.description }
}

async function deleteProject() {
  if (!project.value || !window.confirm(t('admin.projects.deleteConfirm'))) return

  try {
    await projectsService.remove(project.value.id)
    router.push('/admin/projects')
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.projects.actionFailed')
  }
}

// --- Column create/edit/delete ---

const columnModalOpen = ref(false)
const editingColumn = ref<ProjectColumn | null>(null)

function openAddColumn() {
  editingColumn.value = null
  columnModalOpen.value = true
}

function openEditColumn(column: ProjectColumn) {
  editingColumn.value = column
  columnModalOpen.value = true
}

function onColumnSaved(saved: ProjectColumn) {
  if (!project.value) return

  const index = project.value.columns.findIndex((c) => c.id === saved.id)
  if (index === -1) {
    project.value.columns.push({ ...saved, tasks: [] })
  } else {
    project.value.columns[index] = { ...project.value.columns[index], name: saved.name, color: saved.color }
  }
}

async function deleteColumn(column: ProjectColumn) {
  if (!project.value || !window.confirm(t('admin.projects.deleteColumnConfirm'))) return

  try {
    await projectColumnsService.remove(column.id)
    project.value.columns = project.value.columns.filter((c) => c.id !== column.id)
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.projects.actionFailed')
  }
}

// --- Task create/edit/delete ---

const taskModalOpen = ref(false)
const editingTask = ref<ProjectTask | null>(null)
const taskTargetColumnId = ref<number | null>(null)

function openAddTask(column: ProjectColumn) {
  editingTask.value = null
  taskTargetColumnId.value = column.id
  taskModalOpen.value = true
}

function openEditTask(task: ProjectTask) {
  editingTask.value = task
  taskTargetColumnId.value = null
  taskModalOpen.value = true
}

function onTaskSaved(saved: ProjectTask) {
  if (!project.value) return

  for (const column of project.value.columns) {
    const index = column.tasks.findIndex((t) => t.id === saved.id)
    if (index !== -1) {
      column.tasks[index] = saved
      return
    }
  }

  const column = project.value.columns.find((c) => c.id === saved.project_column_id)
  column?.tasks.push(saved)
}

async function deleteTask(task: ProjectTask) {
  if (!project.value || !window.confirm(t('admin.projects.deleteTaskConfirm'))) return

  try {
    await projectTasksService.remove(task.id)
    for (const column of project.value.columns) {
      column.tasks = column.tasks.filter((t) => t.id !== task.id)
    }
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.projects.actionFailed')
  }
}

// --- Column drag-and-drop (reordering lanes) ---

const draggedColumnId = ref<number | null>(null)
const columnDropTargetId = ref<number | null>(null)

function onColumnDragStart(column: ProjectColumn) {
  draggedColumnId.value = column.id
}

function onColumnDragOver(event: DragEvent, column: ProjectColumn) {
  if (draggedColumnId.value === null) return
  event.preventDefault()
  columnDropTargetId.value = column.id
}

async function onColumnDrop(event: DragEvent, target: ProjectColumn) {
  event.preventDefault()
  const draggedId = draggedColumnId.value
  draggedColumnId.value = null
  columnDropTargetId.value = null

  if (!project.value || draggedId === null || draggedId === target.id) return

  const columns = [...project.value.columns]
  const fromIndex = columns.findIndex((c) => c.id === draggedId)
  const toIndex = columns.findIndex((c) => c.id === target.id)
  if (fromIndex === -1 || toIndex === -1) return

  const [moved] = columns.splice(fromIndex, 1)
  columns.splice(toIndex, 0, moved)
  project.value.columns = columns

  try {
    await projectColumnsService.reorder(project.value.id, columns.map((c) => c.id))
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.projects.actionFailed')
    await load()
  }
}

function onColumnDragEnd() {
  draggedColumnId.value = null
  columnDropTargetId.value = null
}

// --- Task drag-and-drop (moving cards between/within columns) ---

const draggedTask = ref<{ id: number; sourceColumnId: number } | null>(null)
const taskDropIndicator = ref<{ columnId: number; insertionPoint: number } | null>(null)

function onTaskDragStart(event: DragEvent, task: ProjectTask, column: ProjectColumn) {
  event.stopPropagation()
  draggedTask.value = { id: task.id, sourceColumnId: column.id }
}

function onCardDragOver(event: DragEvent, column: ProjectColumn, index: number) {
  if (!draggedTask.value) return
  event.preventDefault()
  event.stopPropagation()

  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const isTopHalf = event.clientY < rect.top + rect.height / 2
  taskDropIndicator.value = { columnId: column.id, insertionPoint: isTopHalf ? index : index + 1 }
}

function onColumnBodyDragOver(event: DragEvent, column: ProjectColumn) {
  if (!draggedTask.value) return
  event.preventDefault()
  taskDropIndicator.value = { columnId: column.id, insertionPoint: column.tasks.length }
}

async function onTaskDrop(event: DragEvent, column: ProjectColumn) {
  event.preventDefault()
  const dragged = draggedTask.value
  const indicator = taskDropIndicator.value
  draggedTask.value = null
  taskDropIndicator.value = null

  if (!project.value || !dragged || !indicator || indicator.columnId !== column.id) return

  // Count only the *other* tasks before the drop point — see this file's
  // top docblock for why the backend expects the index this way.
  let finalIndex = 0
  for (let i = 0; i < indicator.insertionPoint && i < column.tasks.length; i++) {
    if (column.tasks[i].id !== dragged.id) finalIndex++
  }

  const sourceColumn = project.value.columns.find((c) => c.id === dragged.sourceColumnId)
  const task = sourceColumn?.tasks.find((t) => t.id === dragged.id)
  if (!task) return

  if (dragged.sourceColumnId === column.id && task.order === finalIndex) return

  const isCrossColumnMove = dragged.sourceColumnId !== column.id
  const moveContext = { taskId: task.id, taskTitle: task.title, fromColumnName: sourceColumn!.name, toColumnName: column.name }

  try {
    await projectTasksService.move(dragged.id, column.id, finalIndex)
    await load()
    if (isCrossColumnMove) moveNotePrompt.value = moveContext
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.projects.actionFailed')
    await load()
  }
}

function onTaskDragEnd() {
  draggedTask.value = null
  taskDropIndicator.value = null
}

// --- Optional note prompt after a cross-column move ---

const moveNotePrompt = ref<{ taskId: number; taskTitle: string; fromColumnName: string; toColumnName: string } | null>(null)
const moveNoteBody = ref('')
const moveNoteSubmitting = ref(false)

function skipMoveNote() {
  moveNotePrompt.value = null
  moveNoteBody.value = ''
}

async function submitMoveNote() {
  if (!moveNotePrompt.value || !moveNoteBody.value.trim()) return

  moveNoteSubmitting.value = true

  try {
    await projectTaskCommentsService.create(moveNotePrompt.value.taskId, moveNoteBody.value.trim())
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.projects.actionFailed')
  } finally {
    moveNoteSubmitting.value = false
    skipMoveNote()
  }
}
</script>

<template>
  <div>
    <RouterLink to="/admin/projects" class="mb-4 inline-flex items-center gap-1 text-sm text-neutral-500 hover:text-neutral-700">
      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
      </svg>
      {{ t('admin.projects.backToProjects') }}
    </RouterLink>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="actionError" variant="danger" class="mb-4" @click="actionError = null">{{ actionError }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-16"><BaseSpinner /></div>

    <template v-else-if="project">
      <div class="mb-6 flex items-start justify-between gap-4">
        <div>
          <h1 class="text-xl font-semibold text-neutral-900">{{ project.name }}</h1>
          <p v-if="project.description" class="mt-1 max-w-2xl text-sm text-neutral-500">{{ project.description }}</p>
        </div>
        <div v-if="canUpdate" class="flex shrink-0 gap-2">
          <BaseButton variant="outline" size="sm" @click="editProjectOpen = true">{{ t('common.edit') }}</BaseButton>
          <BaseButton v-if="canDelete" variant="danger" size="sm" @click="deleteProject">{{ t('admin.projects.delete') }}</BaseButton>
        </div>
      </div>

      <div class="flex gap-4 overflow-x-auto pb-4">
        <div
          v-for="column in project.columns"
          :key="column.id"
          class="flex w-72 shrink-0 flex-col rounded-[--radius-card] bg-neutral-100 transition-opacity"
          :class="[
            draggedColumnId === column.id ? 'opacity-40' : '',
            columnDropTargetId === column.id && draggedColumnId !== column.id ? 'ring-2 ring-primary-400' : '',
          ]"
          @dragover="onColumnDragOver($event, column)"
          @drop="onColumnDrop($event, column)"
        >
          <div
            class="flex items-center justify-between gap-2 rounded-t-[--radius-card] border-b-2 px-3 py-2.5"
            :style="{ borderColor: column.color ?? 'transparent' }"
            :draggable="canUpdate"
            @dragstart="onColumnDragStart(column)"
            @dragend="onColumnDragEnd"
          >
            <div class="flex min-w-0 items-center gap-2">
              <span v-if="column.color" class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: column.color }" />
              <p class="truncate text-sm font-semibold text-neutral-800">{{ column.name }}</p>
              <span class="shrink-0 text-xs text-neutral-400">{{ column.tasks.length }}</span>
            </div>
            <div v-if="canUpdate" class="flex shrink-0 gap-1">
              <button type="button" class="rounded p-1 text-neutral-400 hover:bg-neutral-200 hover:text-neutral-700" @click="openEditColumn(column)">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </button>
              <button type="button" class="rounded p-1 text-neutral-400 hover:bg-danger-50 hover:text-danger-600" @click="deleteColumn(column)">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </div>
          </div>

          <div
            class="flex min-h-[3rem] flex-1 flex-col gap-2 p-2"
            :class="taskDropIndicator?.columnId === column.id ? 'bg-primary-50' : ''"
            @dragover="onColumnBodyDragOver($event, column)"
            @drop="onTaskDrop($event, column)"
          >
            <div
              v-for="(task, index) in column.tasks"
              :key="task.id"
              :draggable="canUpdate"
              class="group relative cursor-pointer rounded-lg border bg-white p-3 shadow-sm transition-colors hover:border-primary-300"
              :class="[
                draggedTask?.id === task.id ? 'opacity-40' : '',
                taskDropIndicator?.columnId === column.id && taskDropIndicator.insertionPoint === index ? 'border-t-2 border-t-primary-500' : 'border-neutral-200',
              ]"
              @dragstart="onTaskDragStart($event, task, column)"
              @dragover="onCardDragOver($event, column, index)"
              @dragend="onTaskDragEnd"
              @click="openEditTask(task)"
            >
              <button
                v-if="canUpdate"
                type="button"
                class="absolute right-1.5 top-1.5 hidden rounded p-1 text-neutral-300 hover:bg-danger-50 hover:text-danger-600 group-hover:block"
                :aria-label="t('admin.projects.deleteTaskConfirm')"
                @click.stop="deleteTask(task)"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
              <p class="pr-4 text-sm font-medium text-neutral-900">{{ task.title }}</p>
              <p v-if="task.description" class="mt-1 line-clamp-2 text-xs text-neutral-500">{{ task.description }}</p>
              <div class="mt-2 flex flex-wrap items-center gap-2">
                <BaseBadge :variant="priorityVariant[task.priority]">{{ t(`admin.projects.priority${task.priority.charAt(0).toUpperCase()}${task.priority.slice(1)}`) }}</BaseBadge>
                <span v-if="task.due_date" class="text-xs text-neutral-400">{{ task.due_date }}</span>
                <span v-if="task.assignee" class="ml-auto truncate text-xs text-neutral-500">{{ task.assignee }}</span>
              </div>
            </div>

            <button
              v-if="canUpdate"
              type="button"
              class="mt-1 rounded-lg px-2 py-1.5 text-left text-sm text-neutral-500 hover:bg-neutral-200"
              @click="openAddTask(column)"
            >
              + {{ t('admin.projects.addTask') }}
            </button>
          </div>
        </div>

        <button
          v-if="canUpdate"
          type="button"
          class="flex h-fit w-72 shrink-0 items-center justify-center gap-1 rounded-[--radius-card] border-2 border-dashed border-neutral-300 py-3 text-sm font-medium text-neutral-500 hover:border-primary-300 hover:text-primary-700"
          @click="openAddColumn"
        >
          + {{ t('admin.projects.addColumn') }}
        </button>
      </div>
    </template>

    <ProjectFormModal v-model="editProjectOpen" :project="project" @saved="onProjectSaved" />
    <ProjectColumnFormModal
      v-if="project"
      v-model="columnModalOpen"
      :project-id="project.id"
      :column="editingColumn"
      @saved="onColumnSaved"
    />
    <ProjectTaskFormModal
      v-model="taskModalOpen"
      :column-id="taskTargetColumnId ?? undefined"
      :task="editingTask"
      @saved="onTaskSaved"
    />

    <BaseModal :model-value="moveNotePrompt !== null" size="sm" :title="t('admin.projects.moveNoteTitle')" @update:model-value="skipMoveNote">
      <template v-if="moveNotePrompt">
        <p class="mb-3 text-sm text-neutral-500">
          {{ t('admin.projects.moveNoteContext', { title: moveNotePrompt.taskTitle, from: moveNotePrompt.fromColumnName, to: moveNotePrompt.toColumnName }) }}
        </p>
        <textarea
          v-model="moveNoteBody"
          rows="2"
          autofocus
          :placeholder="t('admin.projects.moveNotePlaceholder')"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </template>
      <template #footer>
        <BaseButton variant="outline" @click="skipMoveNote">{{ t('admin.projects.moveNoteSkip') }}</BaseButton>
        <BaseButton :loading="moveNoteSubmitting" :disabled="!moveNoteBody.trim()" @click="submitMoveNote">
          {{ t('admin.projects.moveNoteAdd') }}
        </BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
