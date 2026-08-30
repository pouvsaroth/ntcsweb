<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const { t } = useI18n()
const auth = useAuthStore()

const form = reactive({ name: '', phone: '' })
const avatarFile = ref<File | null>(null)
const avatarPreview = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return

    form.name = auth.user?.name ?? ''
    form.phone = auth.user?.phone ?? ''
    avatarFile.value = null
    avatarPreview.value = auth.user?.avatar_url ?? null
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

function onAvatarChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  avatarFile.value = file
  avatarPreview.value = URL.createObjectURL(file)
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await auth.updateProfile({ name: form.name, phone: form.phone, avatar: avatarFile.value })
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('common.editProfileFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="t('common.editProfile')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('common.profilePicture') }}</label>
        <div class="mb-3 flex h-16 w-16 items-center justify-center overflow-hidden rounded-full border border-neutral-200 bg-primary-100">
          <img v-if="avatarPreview" :src="avatarPreview" alt="" class="h-full w-full object-cover" />
          <span v-else class="text-lg font-semibold text-primary-800">{{ form.name.charAt(0) || '?' }}</span>
        </div>
        <input
          type="file"
          accept="image/jpeg,image/png,image/webp,image/gif"
          class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
          @change="onAvatarChange"
        />
        <p v-if="errors.avatar?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.avatar[0] }}</p>
      </div>

      <BaseInput v-model="form.name" required :label="t('common.name')" :error="errors.name?.[0]" />
      <BaseInput v-model="form.phone" required :label="t('common.phone')" :error="errors.phone?.[0]" />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
