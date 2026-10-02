<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { approvalFlowsService, type ApprovalDocumentType, type ApprovalFlow } from '@/services/approvalFlows'
import type { ApprovalGroup } from '@/services/approvalGroups'
import { ApiRequestError } from '@/types/api'

/**
 * Add or edit one item's approval flow: pick the item (only when adding),
 * then the groups that approve it, in order — "Group 1 approves first, then
 * Group 2, …". Removing every step removes the flow.
 */
const props = defineProps<{
  modelValue: boolean
  flows: ApprovalFlow[]
  groups: ApprovalGroup[]
  /** Set when editing an existing item's flow; null when adding one. */
  documentType: ApprovalDocumentType | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const selectedType = ref<ApprovalDocumentType | ''>('')
/** One entry per step, in order — '' until a group is picked. */
const stepGroupIds = ref<string[]>([])
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const isEditing = computed(() => props.documentType !== null)
const hadFlow = computed(() => (props.flows.find((flow) => flow.document_type === props.documentType)?.steps.length ?? 0) > 0)

/** Adding: only items that don't have a flow yet. */
const typeOptions = computed(() =>
  props.flows
    .filter((flow) => isEditing.value || flow.steps.length === 0)
    .map((flow) => ({ value: flow.document_type, label: t(`admin.approvalFlows.types.${flow.document_type}`) })),
)

/** A group can only be one step of the same flow. */
function groupOptions(index: number) {
  const usedElsewhere = new Set(stepGroupIds.value.filter((id, i) => i !== index && id !== ''))
  return props.groups
    .filter((group) => !usedElsewhere.has(String(group.id)))
    .map((group) => ({ value: String(group.id), label: `${group.name} (${t('admin.approvalFlows.memberCount', group.members.length)})` }))
}

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    selectedType.value = props.documentType ?? ''
    const existing = props.flows.find((flow) => flow.document_type === props.documentType)
    stepGroupIds.value = existing && existing.steps.length > 0 ? existing.steps.map((step) => String(step.group.id)) : ['']
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

function addStep() {
  stepGroupIds.value = [...stepGroupIds.value, '']
}

function removeStep(index: number) {
  stepGroupIds.value = stepGroupIds.value.filter((_, i) => i !== index)
}

function moveStep(index: number, direction: -1 | 1) {
  const next = [...stepGroupIds.value]
  const target = index + direction
  ;[next[index], next[target]] = [next[target], next[index]]
  stepGroupIds.value = next
}

function setStep(index: number, value: string) {
  const next = [...stepGroupIds.value]
  next[index] = value
  stepGroupIds.value = next
}

const canSave = computed(() => selectedType.value !== '' && (stepGroupIds.value.some((id) => id !== '') || hadFlow.value))

async function submit() {
  if (selectedType.value === '') return
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await approvalFlowsService.save(
      selectedType.value,
      stepGroupIds.value.filter((id) => id !== '').map(Number),
    )
    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
      generalError.value = Object.values(error.errors)[0]?.[0] ?? null
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.approvalFlows.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.approvalFlows.editTitle') : t('admin.approvalFlows.createTitle')"
    size="lg"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-5" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseSelect
        :model-value="selectedType"
        :options="typeOptions"
        :label="t('admin.approvalFlows.item')"
        :placeholder="t('admin.approvalFlows.selectItem')"
        :disabled="isEditing"
        required
        @update:model-value="selectedType = $event as ApprovalDocumentType | ''"
      />

      <div>
        <p class="text-sm font-medium text-neutral-700">{{ t('admin.approvalFlows.steps') }}</p>
        <p class="mb-3 text-xs text-neutral-500">{{ t('admin.approvalFlows.stepsHint') }}</p>

        <p v-if="groups.length === 0" class="rounded-lg border border-dashed border-neutral-300 p-4 text-center text-sm text-neutral-500">
          {{ t('admin.approvalFlows.noGroups') }}
          <RouterLink to="/admin/approval-flow/groups" class="font-medium text-primary-700 hover:underline">{{ t('adminNav.items.approvalGroups') }}</RouterLink>
        </p>

        <ol v-else class="space-y-2">
          <li v-for="(groupId, index) in stepGroupIds" :key="index" class="flex items-center gap-2">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-600 text-xs font-semibold text-white">{{ index + 1 }}</span>
            <div class="min-w-0 flex-1">
              <BaseSelect
                :model-value="groupId"
                :options="groupOptions(index)"
                :placeholder="t('admin.approvalFlows.selectGroup')"
                @update:model-value="setStep(index, $event)"
              />
            </div>
            <button
              type="button"
              class="rounded p-1.5 text-neutral-500 hover:bg-neutral-100 disabled:opacity-30"
              :disabled="index === 0"
              :aria-label="t('admin.approvalFlows.moveUp')"
              @click="moveStep(index, -1)"
            >
              ↑
            </button>
            <button
              type="button"
              class="rounded p-1.5 text-neutral-500 hover:bg-neutral-100 disabled:opacity-30"
              :disabled="index === stepGroupIds.length - 1"
              :aria-label="t('admin.approvalFlows.moveDown')"
              @click="moveStep(index, 1)"
            >
              ↓
            </button>
            <button
              type="button"
              class="rounded p-1.5 text-danger-600 hover:bg-danger-50"
              :aria-label="t('admin.approvalFlows.removeStep')"
              @click="removeStep(index)"
            >
              ×
            </button>
          </li>
        </ol>

        <BaseButton
          v-if="groups.length > 0"
          variant="outline"
          size="sm"
          class="mt-3"
          :disabled="stepGroupIds.length >= groups.length"
          @click="addStep"
        >
          + {{ t('admin.approvalFlows.addStep') }}
        </BaseButton>

        <p v-if="isEditing && hadFlow && stepGroupIds.every((id) => id === '')" class="mt-3 text-xs text-warning-700">
          {{ t('admin.approvalFlows.removeFlowWarning') }}
        </p>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="!canSave" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
