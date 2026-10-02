<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { approvalGroupsService, type ApprovalGroup, type ApprovalGroupUser } from '@/services/approvalGroups'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  /** Present when editing; absent when adding a new group. */
  group?: ApprovalGroup | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.group != null)

const form = reactive({
  name: '',
  description: '',
  userIds: [] as number[],
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

// --- Member picker -------------------------------------------------------------

const users = ref<ApprovalGroupUser[]>([])
const usersLoading = ref(false)
const userSearch = ref('')

async function loadUsers() {
  usersLoading.value = true
  try {
    users.value = await approvalGroupsService.users()
  } catch {
    generalError.value = t('admin.approvalGroups.usersLoadFailed')
  } finally {
    usersLoading.value = false
  }
}

const filteredUsers = computed(() => {
  const term = userSearch.value.trim().toLowerCase()
  if (!term) return users.value
  return users.value.filter((user) => user.name.toLowerCase().includes(term) || user.email.toLowerCase().includes(term))
})

/** Chips for what's picked — includes members no longer in the user list (e.g. deactivated) so they can still be removed. */
const selectedMembers = computed(() => {
  const known = new Map<number, ApprovalGroupUser>([...(props.group?.members ?? []), ...users.value].map((user) => [user.id, user]))
  return form.userIds.map((id) => known.get(id)).filter((user): user is ApprovalGroupUser => user !== undefined)
})

function toggleUser(id: number) {
  form.userIds = form.userIds.includes(id) ? form.userIds.filter((userId) => userId !== id) : [...form.userIds, id]
}

watch(
  () => [props.modelValue, props.group] as const,
  ([open]) => {
    if (!open) return

    form.name = props.group?.name ?? ''
    form.description = props.group?.description ?? ''
    form.userIds = props.group?.members.map((member) => member.id) ?? []
    userSearch.value = ''
    errors.value = {}
    generalError.value = null
    if (users.value.length === 0) void loadUsers()
  },
  { immediate: true },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const input = { name: form.name, description: form.description.trim() || null, user_ids: form.userIds }

    if (isEditing.value) {
      await approvalGroupsService.update(props.group!.id, input)
    } else {
      await approvalGroupsService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.approvalGroups.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.approvalGroups.editTitle') : t('admin.approvalGroups.createTitle')"
    size="lg"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.name" required :label="t('admin.approvalGroups.name')" :error="errors.name?.[0]" />

      <div>
        <label class="mb-1 block text-sm font-medium text-neutral-700" for="approval-group-description">
          {{ t('admin.approvalGroups.description') }}
        </label>
        <textarea
          id="approval-group-description"
          v-model="form.description"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
        <p v-if="errors.description?.[0]" class="mt-1 text-xs text-danger-600">{{ errors.description[0] }}</p>
      </div>

      <div>
        <p class="text-sm font-medium text-neutral-700">
          {{ t('admin.approvalGroups.members') }}
          <span class="font-normal text-neutral-500">({{ form.userIds.length }})</span>
        </p>
        <p class="mb-2 text-xs text-neutral-500">{{ t('admin.approvalGroups.membersHint') }}</p>

        <div v-if="selectedMembers.length" class="mb-2 flex flex-wrap gap-1.5">
          <span
            v-for="member in selectedMembers"
            :key="member.id"
            class="inline-flex items-center gap-1 rounded-full bg-primary-50 py-0.5 pl-2.5 pr-1 text-xs font-medium text-primary-700"
          >
            {{ member.name }}
            <button
              type="button"
              class="rounded-full px-1 text-primary-500 hover:bg-primary-100 hover:text-primary-800"
              :aria-label="t('admin.approvalGroups.removeMember', { name: member.name })"
              @click="toggleUser(member.id)"
            >
              ×
            </button>
          </span>
        </div>

        <input
          v-model="userSearch"
          type="search"
          :placeholder="t('admin.approvalGroups.searchUsers')"
          class="mb-2 block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />

        <div class="max-h-64 overflow-y-auto rounded-lg border border-neutral-200">
          <p v-if="usersLoading" class="px-3 py-4 text-center text-sm text-neutral-400">{{ t('common.loading') }}</p>
          <p v-else-if="filteredUsers.length === 0" class="px-3 py-4 text-center text-sm text-neutral-400">
            {{ t('admin.approvalGroups.noUsersFound') }}
          </p>
          <label
            v-for="user in filteredUsers"
            v-else
            :key="user.id"
            class="flex cursor-pointer items-center gap-3 border-b border-neutral-100 px-3 py-2 last:border-0 hover:bg-neutral-50"
          >
            <input
              type="checkbox"
              :checked="form.userIds.includes(user.id)"
              class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              @change="toggleUser(user.id)"
            />
            <span class="min-w-0">
              <span class="block truncate text-sm text-neutral-800">{{ user.name }}</span>
              <span class="block truncate text-xs text-neutral-500">{{ user.email }}</span>
            </span>
          </label>
        </div>
        <p v-if="errors.user_ids?.[0]" class="mt-1 text-xs text-danger-600">{{ errors.user_ids[0] }}</p>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
