<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import ProjectFormModal from '@/components/admin/ProjectFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { projectsService, type Project } from '@/services/projects'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()

const projects = ref<Project[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const modalOpen = ref(false)

async function load() {
  loading.value = true
  error.value = null

  try {
    const result = await projectsService.list()
    projects.value = result.data
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.projects.loadFailed')
  } finally {
    loading.value = false
  }
}

function openProject(project: Project) {
  router.push(`/admin/projects/${project.id}`)
}

function onCreated(project: Project) {
  projects.value = [project, ...projects.value]
  router.push(`/admin/projects/${project.id}`)
}

onMounted(() => load())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.projects.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.projects.subtitle') }}</p>
      </div>
      <BaseButton v-if="auth.can('projects.create')" @click="modalOpen = true">{{ t('admin.projects.addProject') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-16"><BaseSpinner /></div>

    <EmptyState
      v-else-if="projects.length === 0"
      :title="t('admin.projects.emptyTitle')"
      :message="t('admin.projects.emptyMessage')"
    />

    <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <button
        v-for="project in projects"
        :key="project.id"
        type="button"
        class="flex flex-col items-start gap-2 rounded-[--radius-card] border border-neutral-200 bg-white p-5 text-left shadow-[--shadow-card] transition-shadow hover:shadow-[--shadow-card-hover]"
        @click="openProject(project)"
      >
        <div class="flex w-full items-start justify-between gap-2">
          <p class="font-semibold text-neutral-900">{{ project.name }}</p>
          <span
            v-if="project.status === 'archived'"
            class="shrink-0 rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-500"
          >
            {{ t('admin.projects.statusArchived') }}
          </span>
        </div>
        <p v-if="project.description" class="line-clamp-2 text-sm text-neutral-500">{{ project.description }}</p>
        <div class="mt-2 flex items-center gap-3 text-xs text-neutral-400">
          <span>{{ t('admin.projects.taskCount', { count: project.task_count ?? 0 }) }}</span>
          <span v-if="project.created_by">{{ t('admin.projects.createdBy', { name: project.created_by }) }}</span>
        </div>
      </button>
    </div>

    <ProjectFormModal v-model="modalOpen" @saved="onCreated" />
  </div>
</template>
