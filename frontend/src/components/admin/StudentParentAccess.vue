<script setup lang="ts">
/**
 * Students > Student record > Guardians, with their Parent portal login:
 * "Give parent access" makes (or reuses) a login on the guardian's phone;
 * siblings with the same guardian phone share it. A new login's temporary
 * password is shown once, to pass on to the parent.
 */
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { parentAccessService, type ParentAccessOverview, type ParentLogin } from '@/services/students'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

const props = defineProps<{ studentId: number; canUpdate: boolean }>()
const { t } = useI18n()

const data = ref<ParentAccessOverview | null>(null)
const failed = ref(false)
const busy = ref<string | null>(null)
const error = ref<string | null>(null)
/** Shown after giving access or resetting a password. */
const result = ref<{ name: string; phone: string | null; password: string | null; children: number; newLogin: boolean } | null>(null)
const confirmRemove = ref<ParentLogin | null>(null)

async function load(): Promise<void> {
  try {
    data.value = await parentAccessService.overview(props.studentId)
  } catch {
    failed.value = true
  }
}
onMounted(load)

function problem(e: unknown): string {
  return e instanceof ApiRequestError ? (e.fieldError('guardian_id') ?? e.message) : t('parentAccess.failed')
}

async function give(guardianId: number): Promise<void> {
  busy.value = `give-${guardianId}`
  error.value = null
  try {
    const r = await parentAccessService.give(props.studentId, guardianId)
    result.value = { name: r.parent.name, phone: r.parent.phone, password: r.temporary_password, children: r.children, newLogin: r.new_login }
    await load()
  } catch (e) {
    error.value = problem(e)
  } finally {
    busy.value = null
  }
}

async function reset(p: ParentLogin): Promise<void> {
  busy.value = `reset-${p.id}`
  error.value = null
  try {
    const r = await parentAccessService.resetPassword(props.studentId, p.id)
    result.value = { name: p.name, phone: p.phone, password: r.temporary_password, children: p.children_count, newLogin: false }
  } catch (e) {
    error.value = problem(e)
  } finally {
    busy.value = null
  }
}

async function remove(): Promise<void> {
  const p = confirmRemove.value
  if (!p) return
  busy.value = `remove-${p.id}`
  error.value = null
  try {
    await parentAccessService.remove(props.studentId, p.id)
    confirmRemove.value = null
    await load()
  } catch (e) {
    error.value = problem(e)
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <div>
    <p v-if="failed" class="text-sm text-neutral-500">{{ t('parentAccess.failed') }}</p>
    <p v-else-if="data && data.guardians.length === 0 && data.others.length === 0" class="text-sm text-neutral-500">{{ t('studentRecord.noGuardians') }}</p>
    <p v-if="error" class="mb-2 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

    <div v-for="g in data?.guardians ?? []" :key="g.id" class="border-b border-neutral-100 py-2 text-sm last:border-0">
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
          <p class="font-medium text-neutral-800">{{ g.name }} <span v-if="g.type" class="font-normal text-neutral-500">({{ g.type }})</span></p>
          <p class="text-xs text-neutral-500">{{ [g.phone, g.email].filter(Boolean).join(' · ') || '—' }}</p>
        </div>
        <BaseBadge v-if="g.parent" :variant="g.parent.status === 'active' ? 'success' : 'neutral'">{{ t('parentAccess.hasLogin') }}</BaseBadge>
      </div>
      <div v-if="g.parent" class="mt-1 text-xs text-neutral-500">
        {{ t('parentAccess.sees', { n: g.parent.children_count }) }}
        <span v-if="g.parent.last_login_at"> · {{ t('parentAccess.lastLogin', { date: formatDate(g.parent.last_login_at) }) }}</span>
        <span v-else> · {{ t('parentAccess.neverLoggedIn') }}</span>
      </div>
      <div v-if="canUpdate" class="mt-1.5 flex flex-wrap gap-2">
        <BaseButton v-if="!g.parent" size="sm" variant="outline" :disabled="!g.can_give" :loading="busy === `give-${g.id}`" @click="give(g.id)">
          {{ t('parentAccess.give') }}
        </BaseButton>
        <template v-else>
          <BaseButton v-if="!g.parent.staff_login" size="sm" variant="ghost" :loading="busy === `reset-${g.parent.id}`" @click="reset(g.parent)">{{ t('parentAccess.resetPassword') }}</BaseButton>
          <BaseButton size="sm" variant="ghost" class="text-red-600" @click="confirmRemove = g.parent">{{ t('parentAccess.remove') }}</BaseButton>
        </template>
      </div>
      <p v-if="canUpdate && !g.parent && !g.can_give" class="mt-1 text-xs text-amber-700">{{ t('parentAccess.noPhone') }}</p>
    </div>

    <!-- Logins whose guardian phone was changed or removed. -->
    <div v-if="data && data.others.length" class="mt-3">
      <p class="text-xs font-semibold text-neutral-600">{{ t('parentAccess.otherLogins') }}</p>
      <div v-for="p in data.others" :key="p.id" class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 py-1.5 text-sm last:border-0">
        <span>{{ p.name }} <span class="text-xs text-neutral-500">{{ p.phone }}</span></span>
        <BaseButton v-if="canUpdate" size="sm" variant="ghost" class="text-red-600" @click="confirmRemove = p">{{ t('parentAccess.remove') }}</BaseButton>
      </div>
    </div>

    <BaseModal :model-value="result !== null" :title="t('parentAccess.doneTitle')" size="sm" @update:model-value="result = null">
      <div v-if="result" class="space-y-3 text-sm">
        <p>{{ result.newLogin || result.password ? t('parentAccess.giveThese', { name: result.name }) : t('parentAccess.linked', { name: result.name, n: result.children }) }}</p>
        <dl v-if="result.password" class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-lg bg-neutral-50 p-3">
          <dt class="text-neutral-500">{{ t('parentAccess.login') }}</dt>
          <dd class="font-mono font-semibold">{{ result.phone }}</dd>
          <dt class="text-neutral-500">{{ t('parentAccess.password') }}</dt>
          <dd class="font-mono font-semibold">{{ result.password }}</dd>
        </dl>
        <p v-if="result.password" class="text-xs text-neutral-500">{{ t('parentAccess.changeHint') }}</p>
        <p v-if="result.children > 1" class="text-xs text-neutral-500">{{ t('parentAccess.siblings', { n: result.children }) }}</p>
        <div class="flex justify-end"><BaseButton @click="result = null">{{ t('parentAccess.ok') }}</BaseButton></div>
      </div>
    </BaseModal>

    <BaseModal :model-value="confirmRemove !== null" :title="t('parentAccess.removeTitle')" size="sm" @update:model-value="confirmRemove = null">
      <div v-if="confirmRemove" class="space-y-3 text-sm">
        <p>{{ t('parentAccess.removeText', { name: confirmRemove.name }) }}</p>
        <div class="flex justify-end gap-2">
          <BaseButton variant="ghost" @click="confirmRemove = null">{{ t('parentAccess.cancel') }}</BaseButton>
          <BaseButton variant="danger" :loading="busy === `remove-${confirmRemove.id}`" @click="remove">{{ t('parentAccess.remove') }}</BaseButton>
        </div>
      </div>
    </BaseModal>
  </div>
</template>
