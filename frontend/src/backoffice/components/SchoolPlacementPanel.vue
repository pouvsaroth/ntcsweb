<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

import { schoolsApi, type Placement, type PlacementMode, type School, type SchoolMove, type ServerLogin } from '../api'
import PlacementChoice from './PlacementChoice.vue'
import ServerFields from './ServerFields.vue'

/**
 * Where the school's data lives, moving it (stage 4d) and its moves —
 * the steps as they run, the row counts, and "Remove old copy".
 */
const props = defineProps<{ school: School; canMove: boolean }>()
const emit = defineEmits<{ changed: [] }>()

const { t } = useI18n()
const confirmDialog = useConfirmDialogStore()

// Never INTO the shared database (its ids would collide there).
const TARGETS: Record<PlacementMode, PlacementMode[]> = { shared: ['dedicated', 'separate'], dedicated: ['separate'], separate: ['dedicated'] }
const targets = computed(() => TARGETS[props.school.placement.mode])

const moves = ref<SchoolMove[]>([])
const error = ref<string | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined

async function load(): Promise<void> {
  clearTimeout(timer)
  try {
    const before = moves.value[0]?.status
    moves.value = await schoolsApi.moves(props.school.id)
    const now = moves.value[0]?.status
    if (now === 'queued' || now === 'running') {
      timer = setTimeout(load, 2000)
    } else if (before && before !== now) {
      emit('changed') // a move just finished: the school's placement and status changed
    }
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

watch(() => props.school.id, load, { immediate: true })
onBeforeUnmount(() => clearTimeout(timer))

function where(p: Pick<Placement, 'mode' | 'database' | 'host'>): string {
  return p.host ? `${p.host} / ${p.database}` : p.database
}

// ── Move dialog ────────────────────────────────────────────────────────
const open = ref(false)
const to = ref<PlacementMode>('dedicated')
const server = ref<ServerLogin>({ host: '', port: 5432, database: '', username: '', password: '', sslmode: 'require' })
const starting = ref(false)
const moveError = ref<ApiRequestError | null>(null)

function openMove(): void {
  to.value = targets.value[0]
  moveError.value = null
  open.value = true
}

async function start(): Promise<void> {
  starting.value = true
  moveError.value = null
  try {
    await schoolsApi.move(props.school.id, to.value, to.value === 'separate' ? { ...server.value, port: Number(server.value.port) } : undefined)
    open.value = false
    await load()
  } catch (e) {
    moveError.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    starting.value = false
  }
}

async function removeOld(move: SchoolMove): Promise<void> {
  if (!(await confirmDialog.confirm({ message: t('bo.placement.removeOldConfirm', { where: where(move.from) }), danger: true }))) return
  try {
    await schoolsApi.removeOldCopy(move.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (e.fieldError('move') ?? e.message) : t('bo.common.failed')
  }
}

const statusVariant = { queued: 'neutral', running: 'primary', done: 'success', failed: 'danger' } as const

function rowsSummary(move: SchoolMove): string | null {
  const source = move.counts?.source
  if (!source) return null
  const n = Object.values(source).reduce((a, b) => a + b, 0)
  return t('bo.placement.rows', { n, t: Object.keys(source).length })
}
</script>

<template>
  <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-semibold text-neutral-900">{{ t('bo.placement.title') }}</h2>
      <BaseButton v-if="canMove && targets.length && ['active', 'suspended'].includes(school.status)" size="sm" variant="outline" @click="openMove">
        {{ t('bo.placement.move') }}
      </BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-3">{{ error }}</BaseAlert>

    <dl class="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
      <div>
        <dt class="text-xs text-neutral-500">{{ t('bo.placement.current') }}</dt>
        <dd><BaseBadge variant="primary">{{ t(`bo.placement.${school.placement.mode}`) }}</BaseBadge></dd>
      </div>
      <div>
        <dt class="text-xs text-neutral-500">{{ t('bo.placement.database') }}</dt>
        <dd class="font-mono break-all text-neutral-800">{{ school.placement.database }}</dd>
      </div>
      <div v-if="school.placement.host">
        <dt class="text-xs text-neutral-500">{{ t('bo.placement.server') }}</dt>
        <dd class="font-mono break-all text-neutral-800">{{ school.placement.host }}:{{ school.placement.port }} · {{ school.placement.username }}</dd>
      </div>
    </dl>

    <div v-if="moves.length" class="mt-5">
      <h3 class="mb-2 text-sm font-medium text-neutral-700">{{ t('bo.placement.moves') }}</h3>
      <ul class="space-y-3">
        <li v-for="move in moves" :key="move.id" class="rounded-lg border border-neutral-200 p-3 text-sm">
          <div class="flex flex-wrap items-center gap-2">
            <BaseBadge :variant="statusVariant[move.status]">{{ t(`bo.placement.statuses.${move.status}`) }}</BaseBadge>
            <span class="text-neutral-800">{{ t(`bo.placement.${move.from.mode}`) }} → {{ t(`bo.placement.${move.to.mode}`) }}</span>
            <span class="text-xs text-neutral-400">{{ formatDateTime(move.created_at) }}{{ move.staff ? ` · ${move.staff}` : '' }}</span>
          </div>
          <ol class="mt-2 flex flex-wrap gap-1.5">
            <li
              v-for="step in move.steps"
              :key="step.step"
              class="rounded px-1.5 py-0.5 text-xs"
              :class="step.status === 'ok' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'"
              :title="step.message ?? ''"
            >
              {{ step.status === 'ok' ? '✓' : '✕' }} {{ t(`bo.placement.steps.${step.step}`) }}
            </li>
          </ol>
          <p v-if="rowsSummary(move)" class="mt-2 text-xs text-neutral-600">{{ rowsSummary(move) }}</p>
          <p v-if="move.error" class="mt-2 font-mono text-xs break-all text-red-700">{{ move.error }}</p>
          <div v-if="move.status === 'done'" class="mt-2 flex flex-wrap items-center gap-2 text-xs text-neutral-500">
            <template v-if="move.old_copy_removed_at">{{ t('bo.placement.oldRemoved', { when: formatDateTime(move.old_copy_removed_at) }) }}</template>
            <template v-else>
              <span>{{ t('bo.placement.oldKept', { where: where(move.from) }) }}</span>
              <BaseButton v-if="canMove" size="sm" variant="ghost" @click="removeOld(move)">{{ t('bo.placement.removeOld') }}</BaseButton>
            </template>
          </div>
        </li>
      </ul>
    </div>
    <p v-else class="mt-4 text-xs text-neutral-400">{{ t('bo.placement.noMoves') }}</p>

    <BaseModal v-model="open" :title="t('bo.placement.moveTitle')" size="lg">
      <form id="bo-move" class="space-y-4" @submit.prevent="start">
        <p class="text-sm text-neutral-600">{{ t('bo.placement.moveHint') }}</p>
        <BaseAlert v-if="moveError && !moveError.errors?.server" variant="danger">{{ moveError.fieldError('placement') ?? moveError.message }}</BaseAlert>
        <div>
          <p class="mb-2 text-sm font-medium text-neutral-700">{{ t('bo.placement.moveTo') }}</p>
          <PlacementChoice v-model="to" :options="targets" />
        </div>
        <ServerFields v-if="to === 'separate'" v-model="server" :errors="moveError" />
      </form>
      <template #footer>
        <BaseButton variant="ghost" @click="open = false">{{ t('bo.common.cancel') }}</BaseButton>
        <BaseButton type="submit" form="bo-move" :loading="starting">{{ t('bo.placement.start') }}</BaseButton>
      </template>
    </BaseModal>
  </section>
</template>
