<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

import { schoolsApi, type School } from '../api'
import ReasonModal from '../components/ReasonModal.vue'
import SchoolDomainsPanel from '../components/SchoolDomainsPanel.vue'
import SchoolOwnerPanel from '../components/SchoolOwnerPanel.vue'
import SchoolPlacementPanel from '../components/SchoolPlacementPanel.vue'
import SchoolProfileForm from '../components/SchoolProfileForm.vue'
import SchoolSetupPanel from '../components/SchoolSetupPanel.vue'
import SchoolStatusBadge from '../components/SchoolStatusBadge.vue'
import { useSession } from '../session'

/** One school: setup progress, profile, owner, status, domains, history. Polls while setting up. */
const { t } = useI18n()
const route = useRoute()
const session = useSession()
const confirmDialog = useConfirmDialogStore()

const id = computed(() => Number(route.params.id))
const school = ref<School | null>(null)
const error = ref<string | null>(null)
const canWrite = computed(() => session.can('platform.school.write'))
const canStatus = computed(() => session.can('platform.school.status'))

let timer: ReturnType<typeof setTimeout> | undefined

async function load(): Promise<void> {
  clearTimeout(timer)
  try {
    school.value = await schoolsApi.show(id.value)
    error.value = null
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
  if (school.value?.status === 'provisioning') timer = setTimeout(load, 2000)
}

watch(id, load, { immediate: true })
onBeforeUnmount(() => clearTimeout(timer))

const retrying = ref(false)
async function retry(): Promise<void> {
  retrying.value = true
  try {
    school.value = await schoolsApi.retry(id.value)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    retrying.value = false
  }
}

const reasonFor = ref<'suspend' | 'archive' | null>(null)
const reasonBusy = ref(false)
const reasonError = ref<string | undefined>(undefined)

async function confirmReason(reason: string): Promise<void> {
  if (!reasonFor.value) return
  reasonBusy.value = true
  reasonError.value = undefined
  try {
    school.value = reasonFor.value === 'suspend' ? await schoolsApi.suspend(id.value, reason) : await schoolsApi.archive(id.value, reason)
    reasonFor.value = null
    await load()
  } catch (e) {
    reasonError.value = e instanceof ApiRequestError ? (e.fieldError('reason') ?? e.fieldError('status') ?? e.message) : t('bo.common.failed')
  } finally {
    reasonBusy.value = false
  }
}

async function reactivate(): Promise<void> {
  if (!school.value || !(await confirmDialog.confirm(t('bo.schools.reactivateConfirm', { name: school.value.name })))) return
  try {
    await schoolsApi.reactivate(id.value)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (e.fieldError('status') ?? e.message) : t('bo.common.failed')
  }
}
</script>

<template>
  <div>
    <RouterLink to="/schools" class="mb-4 inline-flex items-center gap-1 text-sm text-neutral-500 hover:text-neutral-800">
      ← {{ t('bo.schools.back') }}
    </RouterLink>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="!school && !error" class="flex justify-center py-16"><BaseSpinner /></div>

    <template v-if="school">
      <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <h1 class="text-xl font-semibold text-neutral-900">{{ school.name }}</h1>
            <SchoolStatusBadge :status="school.status" />
          </div>
          <p v-if="school.name_en" class="text-sm text-neutral-500">{{ school.name_en }}</p>
        </div>

        <div v-if="canStatus" class="flex flex-wrap gap-2">
          <BaseButton v-if="school.status === 'active'" variant="outline" size="sm" @click="reasonFor = 'suspend'">
            {{ t('bo.schools.suspend') }}
          </BaseButton>
          <BaseButton v-if="school.status === 'suspended'" size="sm" @click="reactivate">{{ t('bo.schools.reactivate') }}</BaseButton>
          <BaseButton v-if="school.status === 'active' || school.status === 'suspended'" variant="danger" size="sm" @click="reasonFor = 'archive'">
            {{ t('bo.schools.archive') }}
          </BaseButton>
        </div>
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
          <SchoolSetupPanel
            v-if="school.status === 'provisioning' || school.status === 'failed' || (school.provisioning_log?.length ?? 0) > 0"
            :school="school"
            :can-retry="canWrite"
            :retrying="retrying"
            @retry="retry"
          />
          <SchoolPlacementPanel
            v-if="school.status !== 'provisioning'"
            :school="school"
            :can-move="session.can('platform.school.placement')"
            @changed="load"
          />
          <SchoolProfileForm :school="school" :can-write="canWrite" @saved="load" />
          <SchoolDomainsPanel v-if="school.status === 'active' || school.status === 'suspended'" :school="school" :can-write="canWrite" @changed="load" />
        </div>

        <div class="space-y-6">
          <SchoolOwnerPanel :school="school" :can-write="canWrite" @changed="load" />

          <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
            <h2 class="mb-3 font-semibold text-neutral-900">{{ t('bo.schools.history') }}</h2>
            <ol class="space-y-3">
              <li v-for="(change, i) in school.status_history ?? []" :key="i" class="text-sm">
                <p class="text-neutral-800">
                  <span v-if="change.from">{{ t(`bo.schools.statuses.${change.from}`) }} → </span>{{ t(`bo.schools.statuses.${change.to}`) }}
                </p>
                <p v-if="change.reason" class="text-xs text-neutral-600">“{{ change.reason }}”</p>
                <p class="text-xs text-neutral-400">
                  {{ formatDateTime(change.at) }} · {{ change.staff ? t('bo.schools.by', { name: change.staff }) : t('bo.schools.system') }}
                </p>
              </li>
            </ol>
          </section>
        </div>
      </div>

      <ReasonModal
        :model-value="reasonFor !== null"
        :title="reasonFor === 'archive' ? t('bo.schools.archive') : t('bo.schools.suspend')"
        :hint="reasonFor === 'archive' ? t('bo.schools.archiveHint') : t('bo.schools.suspendHint')"
        :confirm-label="reasonFor === 'archive' ? t('bo.schools.archive') : t('bo.schools.suspend')"
        :busy="reasonBusy"
        :error="reasonError"
        @update:model-value="reasonFor = null"
        @confirm="confirmReason"
      />
    </template>
  </div>
</template>
