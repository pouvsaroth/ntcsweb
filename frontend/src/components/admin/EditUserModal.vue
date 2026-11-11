<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { adminUsersService, type UserClassAccess } from '@/services/adminUsers'
import { apiGetWithMeta } from '@/services/http'
import { rolesService, type Role } from '@/services/roles'
import { ApiRequestError } from '@/types/api'
import type { User } from '@/types/models'

const props = defineProps<{
  modelValue: boolean
  user: User | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const roles = ref<Role[]>([])
const roleOptions = computed(() => roles.value.map((role) => ({ value: String(role.id), label: role.name })))

/** A student-linked account's role is always forced to Student — see StoreUserRequest — so it's not offered here. */
const isStudentLinked = computed(() => props.user?.student_id != null)

const form = reactive({ name: '', phone: '', email: '', role_id: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

onMounted(async () => {
  roles.value = await rolesService.listAll()
})

watch(
  () => [props.modelValue, props.user] as const,
  ([open, user]) => {
    if (!open || !user) return
    form.name = user.name
    form.phone = user.phone ?? ''
    form.email = user.email ?? ''
    form.role_id = user.roles?.[0] ? String(user.roles[0].id) : ''
    errors.value = {}
    generalError.value = null
    void loadAccess(user)
  },
  { immediate: true },
)

// ── Extra classes (Roles > Data view: assigned classes only) ──────────────
/** null when this account may not be given extra classes by you (yourself, or a higher role). */
const access = ref<UserClassAccess | null>(null)
const allClasses = ref<{ id: number; name: string; code: string | null }[]>([])
const classIds = ref<number[]>([])
const classSearch = ref('')

const teachingIds = computed(() => new Set(access.value?.teaching_classes.map((c) => c.id) ?? []))
const pickableClasses = computed(() => {
  const q = classSearch.value.trim().toLowerCase()
  return allClasses.value
    .filter((c) => !teachingIds.value.has(c.id))
    .filter((c) => !q || c.name.toLowerCase().includes(q) || (c.code ?? '').toLowerCase().includes(q))
})

async function loadAccess(user: User) {
  access.value = null
  classIds.value = []
  classSearch.value = ''
  if (props.user?.student_id != null) return
  try {
    const [state, classes] = await Promise.all([
      adminUsersService.classes(user.id),
      allClasses.value.length ? Promise.resolve(null) : apiGetWithMeta<{ id: number; name: string; code: string | null }[]>('/classes', { params: { per_page: 500, sort: 'name' } }),
    ])
    if (classes) allClasses.value = classes.data
    access.value = state
    classIds.value = [...state.class_ids]
  } catch {
    access.value = null
  }
}

function toggleClass(id: number, on: boolean) {
  classIds.value = on ? [...classIds.value, id] : classIds.value.filter((c) => c !== id)
}

function close() {
  emit('update:modelValue', false)
}

async function submit() {
  if (!props.user) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await adminUsersService.update(props.user.id, {
      name: form.name,
      phone: form.phone,
      email: form.email,
      ...(isStudentLinked.value ? {} : { role_id: form.role_id ? Number(form.role_id) : undefined }),
    })
    if (access.value) {
      const before = [...access.value.class_ids].sort((a, b) => a - b).join(',')
      const after = [...classIds.value].sort((a, b) => a - b).join(',')
      if (before !== after) await adminUsersService.setClasses(props.user.id, classIds.value)
    }
    emit('saved')
    close()
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.users.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.users.editTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form v-if="user" class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.name" required :label="t('admin.users.name')" :error="errors.name?.[0]" />
      <BaseInput v-model="form.phone" :label="t('admin.users.phone')" :error="errors.phone?.[0]" />
      <BaseInput v-model="form.email" type="email" :label="t('admin.users.email')" :error="errors.email?.[0]" />

      <BaseSelect
        v-if="!isStudentLinked"
        v-model="form.role_id"
        :options="roleOptions"
        :placeholder="t('admin.users.selectRole')"
        :label="t('admin.users.role')"
        :error="errors.role_id?.[0]"
      />
      <p v-else class="text-sm text-neutral-500">{{ t('admin.users.roleLockedToStudent') }}</p>

      <section v-if="access" class="rounded-lg border border-neutral-200 p-3">
        <h3 class="text-sm font-medium text-neutral-800">{{ t('admin.users.extraClasses') }}</h3>
        <p class="mb-2 text-xs text-neutral-500">{{ access.sees_all ? t('admin.users.extraClassesSeesAll') : t('admin.users.extraClassesHint') }}</p>

        <p class="mb-1 text-xs font-medium text-neutral-600">{{ t('admin.users.teachingClasses') }}</p>
        <p v-if="access.teaching_classes.length === 0" class="mb-2 text-xs text-neutral-400">{{ t('admin.users.noTeachingClasses') }}</p>
        <div v-else class="mb-2 flex flex-wrap gap-1.5">
          <span v-for="c in access.teaching_classes" :key="c.id" class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-700">{{ c.name }}</span>
        </div>

        <BaseInput v-model="classSearch" :placeholder="t('admin.users.searchClasses')" class="mb-2" />
        <div class="max-h-56 space-y-1 overflow-y-auto rounded border border-neutral-100 p-2">
          <p v-if="pickableClasses.length === 0" class="text-xs text-neutral-400">{{ t('admin.users.noClassesFound') }}</p>
          <label v-for="c in pickableClasses" :key="c.id" class="flex items-center gap-2 text-sm text-neutral-700">
            <input
              type="checkbox"
              :checked="classIds.includes(c.id)"
              class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              @change="toggleClass(c.id, ($event.target as HTMLInputElement).checked)"
            />
            <span>{{ c.name }}</span>
            <span v-if="c.code" class="text-xs text-neutral-400">{{ c.code }}</span>
          </label>
        </div>
        <p class="mt-1 text-xs text-neutral-500">{{ t('admin.users.extraClassesPicked', { n: classIds.length }) }}</p>
      </section>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="close">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
