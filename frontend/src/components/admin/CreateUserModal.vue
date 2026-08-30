<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { adminUsersService } from '@/services/adminUsers'
import { rolesService, type Role } from '@/services/roles'
import { studentsService, type Student } from '@/services/students'
import { ApiRequestError } from '@/types/api'

defineProps<{ modelValue: boolean }>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const accountType = ref<'student' | 'standalone'>('student')

const studentSearch = ref('')
const studentResults = ref<Student[]>([])
const selectedStudent = ref<Student | null>(null)
let studentSearchDebounce: ReturnType<typeof setTimeout> | undefined

const roles = ref<Role[]>([])

const form = reactive({
  name: '',
  phone: '',
  email: '',
  role_id: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const temporaryPassword = ref<string | null>(null)

const roleOptions = computed(() => roles.value.map((role) => ({ value: String(role.id), label: role.name })))

onMounted(async () => {
  roles.value = await rolesService.listAll()
})

function onStudentSearchInput() {
  clearTimeout(studentSearchDebounce)
  if (!studentSearch.value.trim()) {
    studentResults.value = []
    return
  }
  studentSearchDebounce = setTimeout(async () => {
    const result = await studentsService.list({ search: studentSearch.value })
    studentResults.value = result.data
  }, 350)
}

function selectStudent(student: Student) {
  selectedStudent.value = student
  studentResults.value = []
  studentSearch.value = ''
  form.name = student.full_name
}

function resetForm() {
  accountType.value = 'student'
  studentSearch.value = ''
  studentResults.value = []
  selectedStudent.value = null
  form.name = ''
  form.phone = ''
  form.email = ''
  form.role_id = ''
  errors.value = {}
  generalError.value = null
  temporaryPassword.value = null
}

function close() {
  emit('update:modelValue', false)
  resetForm()
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const { temporaryPassword: password } = await adminUsersService.create({
      name: form.name,
      phone: form.phone,
      email: form.email,
      ...(accountType.value === 'student'
        ? { student_id: selectedStudent.value?.id }
        : { role_id: form.role_id ? Number(form.role_id) : undefined }),
    })

    emit('saved')
    temporaryPassword.value = password
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
  <BaseModal
    :model-value="modelValue"
    :title="temporaryPassword ? t('admin.users.temporaryPasswordTitle') : t('admin.users.createTitle')"
    @update:model-value="
      (value) => {
        emit('update:modelValue', value)
        if (!value) resetForm()
      }
    "
  >
    <div v-if="temporaryPassword" class="space-y-4">
      <BaseAlert variant="success">{{ t('admin.users.temporaryPasswordMessage') }}</BaseAlert>
      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.users.temporaryPasswordLabel') }}</label>
        <code class="block rounded-lg border border-neutral-300 bg-neutral-50 px-3 py-2 text-sm font-mono text-neutral-900">
          {{ temporaryPassword }}
        </code>
      </div>
    </div>

    <form v-else class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div class="flex gap-2">
        <button
          type="button"
          class="flex-1 rounded-lg border px-3 py-2 text-sm font-medium"
          :class="accountType === 'student' ? 'border-primary-500 bg-primary-50 text-primary-800' : 'border-neutral-300 text-neutral-600'"
          @click="accountType = 'student'"
        >
          {{ t('admin.users.linkExistingStudent') }}
        </button>
        <button
          type="button"
          class="flex-1 rounded-lg border px-3 py-2 text-sm font-medium"
          :class="accountType === 'standalone' ? 'border-primary-500 bg-primary-50 text-primary-800' : 'border-neutral-300 text-neutral-600'"
          @click="accountType = 'standalone'"
        >
          {{ t('admin.users.standaloneAccount') }}
        </button>
      </div>

      <div v-if="accountType === 'student'">
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.users.student') }}</label>
        <p class="mb-2 text-xs text-neutral-500">{{ t('admin.users.studentHint') }}</p>

        <div v-if="selectedStudent" class="flex items-center justify-between rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm">
          <span>{{ selectedStudent.full_name }} <span class="text-neutral-400">({{ selectedStudent.student_code }})</span></span>
          <button type="button" class="font-medium text-secondary-600 hover:text-secondary-700" @click="selectedStudent = null">
            {{ t('common.change') }}
          </button>
        </div>
        <template v-else>
          <input
            v-model="studentSearch"
            type="search"
            :placeholder="t('admin.users.selectStudent')"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
            @input="onStudentSearchInput"
          />
          <ul v-if="studentResults.length > 0" class="mt-1 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
            <li v-for="student in studentResults" :key="student.id">
              <button
                type="button"
                class="block w-full px-3 py-2 text-left text-sm hover:bg-neutral-50"
                @click="selectStudent(student)"
              >
                {{ student.full_name }} <span class="text-neutral-400">({{ student.student_code }})</span>
              </button>
            </li>
          </ul>
        </template>
        <p v-if="errors.student_id?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.student_id[0] }}</p>
      </div>

      <BaseSelect
        v-else
        v-model="form.role_id"
        required
        :options="roleOptions"
        :placeholder="t('admin.users.selectRole')"
        :label="t('admin.users.role')"
        :error="errors.role_id?.[0]"
      />

      <BaseInput v-model="form.name" required :label="t('admin.users.name')" :error="errors.name?.[0]" />
      <BaseInput v-model="form.phone" required :label="t('admin.users.phone')" :error="errors.phone?.[0]" />
      <BaseInput v-model="form.email" type="email" :label="t('admin.users.email')" :error="errors.email?.[0]" />
    </form>

    <template #footer>
      <template v-if="temporaryPassword">
        <BaseButton @click="close">{{ t('admin.users.close') }}</BaseButton>
      </template>
      <template v-else>
        <BaseButton variant="outline" @click="close">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
      </template>
    </template>
  </BaseModal>
</template>
