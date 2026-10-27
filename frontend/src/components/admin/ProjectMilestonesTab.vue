<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ProjectMilestoneFormModal from '@/components/admin/ProjectMilestoneFormModal.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { projectMilestonesService, type Project, type ProjectMilestone, type ProjectTask } from '@/services/projects'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { useConfirmDialogStore } from '@/stores/confirmDialog'

/**
 * The project's Milestones tab: each milestone with its dates, its cards and
 * how many are done. "Done" means the card sits in the board's last column.
 */
const props = defineProps<{ project: Project; canUpdate: boolean }>()
const emit = defineEmits<{ 'open-task': [task: ProjectTask]; error: [message: string] }>()

const { t } = useI18n()
const confirmDialog = useConfirmDialogStore()

type Status = 'done' | 'overdue' | 'inProgress' | 'notStarted' | 'empty'
const statusVariant: Record<Status, 'neutral' | 'primary' | 'success' | 'danger'> = {
  done: 'success',
  overdue: 'danger',
  inProgress: 'primary',
  notStarted: 'neutral',
  empty: 'neutral',
}

function localToday(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const lastColumn = computed(() => props.project.columns[props.project.columns.length - 1] ?? null)
const columnName = computed(() => new Map(props.project.columns.map((c) => [c.id, c.name])))

const rows = computed(() => {
  const today = localToday()
  const tasks = props.project.columns.flatMap((c) => c.tasks)

  return [...props.project.milestones]
    .sort((a, b) => a.order - b.order)
    .map((milestone) => {
      const own = tasks.filter((task) => task.project_milestone_id === milestone.id)
      const done = own.filter((task) => task.project_column_id === lastColumn.value?.id).length
      let status: Status
      if (own.length === 0) status = 'empty'
      else if (done === own.length) status = 'done'
      else if (milestone.due_date && milestone.due_date < today) status = 'overdue'
      else if (milestone.start_date && milestone.start_date > today) status = 'notStarted'
      else status = 'inProgress'

      return { milestone, tasks: own, done, percent: own.length ? Math.round((done / own.length) * 100) : 0, status }
    })
})

const unassignedCount = computed(() => props.project.columns.flatMap((c) => c.tasks).filter((task) => task.project_milestone_id === null).length)

const expanded = ref<Set<number>>(new Set())
function toggle(id: number) {
  const next = new Set(expanded.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expanded.value = next
}

const modalOpen = ref(false)
const editing = ref<ProjectMilestone | null>(null)

function openAdd() {
  editing.value = null
  modalOpen.value = true
}

function openEdit(milestone: ProjectMilestone) {
  editing.value = milestone
  modalOpen.value = true
}

function onSaved(saved: ProjectMilestone) {
  const list = props.project.milestones
  const index = list.findIndex((m) => m.id === saved.id)
  if (index === -1) list.push(saved)
  else list[index] = saved
  for (const column of props.project.columns) {
    for (const task of column.tasks) {
      if (task.project_milestone_id === saved.id) task.milestone = { id: saved.id, name: saved.name }
    }
  }
}

async function remove(milestone: ProjectMilestone) {
  if (!(await confirmDialog.confirm({ message: t('admin.projects.deleteMilestoneConfirm', { name: milestone.name }), danger: true }))) return

  try {
    await projectMilestonesService.remove(milestone.id)
    const list = props.project.milestones
    list.splice(list.findIndex((m) => m.id === milestone.id), 1)
    // The cards stay; they just no longer show a milestone.
    for (const column of props.project.columns) {
      for (const task of column.tasks) {
        if (task.project_milestone_id === milestone.id) {
          task.project_milestone_id = null
          task.milestone = null
        }
      }
    }
  } catch (e) {
    emit('error', e instanceof ApiRequestError ? e.message : t('admin.projects.actionFailed'))
  }
}
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-neutral-500">
        {{ lastColumn ? t('admin.projects.milestoneDoneHint', { column: lastColumn.name }) : '' }}
        <span v-if="unassignedCount > 0"> · {{ t('admin.projects.milestoneUnassigned', { n: unassignedCount }) }}</span>
      </p>
      <BaseButton v-if="canUpdate" size="sm" @click="openAdd">+ {{ t('admin.projects.addMilestone') }}</BaseButton>
    </div>

    <p v-if="rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('admin.projects.noMilestones') }}
    </p>

    <ul v-else class="space-y-3">
      <li v-for="row in rows" :key="row.milestone.id" class="rounded-[--radius-card] border border-neutral-200 bg-white">
        <div class="flex flex-wrap items-start gap-3 p-4">
          <button type="button" class="min-w-0 flex-1 text-left" @click="toggle(row.milestone.id)">
            <div class="flex flex-wrap items-center gap-2">
              <svg
                class="h-4 w-4 shrink-0 text-neutral-400 transition-transform"
                :class="expanded.has(row.milestone.id) ? 'rotate-90' : ''"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
              >
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
              </svg>
              <p class="font-semibold text-neutral-900">{{ row.milestone.name }}</p>
              <BaseBadge :variant="statusVariant[row.status]">{{ t(`admin.projects.milestoneStatus.${row.status}`) }}</BaseBadge>
            </div>
            <p class="mt-1 pl-6 text-xs text-neutral-500">
              {{ formatDate(row.milestone.start_date) || '—' }} → {{ formatDate(row.milestone.due_date) || '—' }}
            </p>
            <div class="mt-3 flex items-center gap-3 pl-6">
              <div class="h-2 flex-1 overflow-hidden rounded-full bg-neutral-100">
                <div class="h-full rounded-full" :class="row.status === 'overdue' ? 'bg-danger-500' : 'bg-success-500'" :style="{ width: `${row.percent}%` }" />
              </div>
              <span class="whitespace-nowrap text-xs font-medium text-neutral-600">
                {{ t('admin.projects.milestoneProgress', { done: row.done, total: row.tasks.length }) }} ({{ row.percent }}%)
              </span>
            </div>
          </button>
          <div v-if="canUpdate" class="flex shrink-0 gap-1">
            <button type="button" class="rounded p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" :aria-label="t('common.edit')" @click="openEdit(row.milestone)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
            </button>
            <button type="button" class="rounded p-1.5 text-neutral-400 hover:bg-danger-50 hover:text-danger-600" :aria-label="t('admin.projects.deleteMilestone')" @click="remove(row.milestone)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
          </div>
        </div>

        <div v-if="expanded.has(row.milestone.id)" class="border-t border-neutral-100 px-4 py-2">
          <p v-if="row.tasks.length === 0" class="py-2 text-sm text-neutral-500">{{ t('admin.projects.milestoneNoCards') }}</p>
          <button
            v-for="task in row.tasks"
            :key="task.id"
            type="button"
            class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left text-sm hover:bg-neutral-50"
            @click="emit('open-task', task)"
          >
            <span
              class="h-2 w-2 shrink-0 rounded-full"
              :class="task.project_column_id === lastColumn?.id ? 'bg-success-500' : 'bg-neutral-300'"
            />
            <span class="min-w-0 flex-1 truncate text-neutral-800">{{ task.title }}</span>
            <span class="hidden shrink-0 text-xs text-neutral-500 sm:inline">{{ task.assignee ?? '' }}</span>
            <span class="shrink-0 rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">{{ columnName.get(task.project_column_id) }}</span>
          </button>
        </div>
      </li>
    </ul>

    <ProjectMilestoneFormModal v-model="modalOpen" :project-id="project.id" :milestone="editing" @saved="onSaved" />
  </div>
</template>
