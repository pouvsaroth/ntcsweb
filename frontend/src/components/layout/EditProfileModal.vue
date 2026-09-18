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

const uploadInput = ref<HTMLInputElement | null>(null)
const backCameraInput = ref<HTMLInputElement | null>(null)
const frontCameraInput = ref<HTMLInputElement | null>(null)

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
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return

  avatarFile.value = file
  avatarPreview.value = URL.createObjectURL(file)
  // Cleared so re-picking the same file (e.g. retaking a photo that lands
  // at the same path) still fires @change next time.
  input.value = ''
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

        <!-- Three explicit sources rather than one plain file input: a
             gallery pick, plus a `capture` input per camera (back/front) so
             a phone opens straight into that camera instead of a chooser.
             `capture` is simply ignored on desktop, where all three just
             open the normal file browser. -->
        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-lg bg-primary-50 px-3 py-2 text-sm font-medium text-primary-800 hover:bg-primary-100"
            @click="uploadInput?.click()"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 7.5L12 3m0 0L7.5 7.5M12 3v13.5" />
            </svg>
            {{ t('common.uploadPhoto') }}
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-lg bg-primary-50 px-3 py-2 text-sm font-medium text-primary-800 hover:bg-primary-100"
            @click="backCameraInput?.click()"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.25 2.25 0 018.92 4.5h6.16c.891 0 1.71.484 2.083 1.267l.415.865a1.5 1.5 0 001.348.868h.334c1.036 0 1.875.84 1.875 1.875v9.375A2.25 2.25 0 0118.875 21H5.625a2.25 2.25 0 01-2.25-2.25V9.375c0-1.036.84-1.875 1.875-1.875h.334a1.5 1.5 0 001.35-.868l.893-.457z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z" />
            </svg>
            {{ t('common.takePhotoBack') }}
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-lg bg-primary-50 px-3 py-2 text-sm font-medium text-primary-800 hover:bg-primary-100"
            @click="frontCameraInput?.click()"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.25 2.25 0 018.92 4.5h6.16c.891 0 1.71.484 2.083 1.267l.415.865a1.5 1.5 0 001.348.868h.334c1.036 0 1.875.84 1.875 1.875v9.375A2.25 2.25 0 0118.875 21H5.625a2.25 2.25 0 01-2.25-2.25V9.375c0-1.036.84-1.875 1.875-1.875h.334a1.5 1.5 0 001.35-.868l.893-.457z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v4.5m-2.25-2.25h4.5" />
            </svg>
            {{ t('common.takePhotoFront') }}
          </button>
        </div>

        <input
          ref="uploadInput"
          type="file"
          accept="image/jpeg,image/png,image/webp,image/gif"
          class="hidden"
          @change="onAvatarChange"
        />
        <input
          ref="backCameraInput"
          type="file"
          accept="image/jpeg,image/png,image/webp,image/gif"
          capture="environment"
          class="hidden"
          @change="onAvatarChange"
        />
        <input
          ref="frontCameraInput"
          type="file"
          accept="image/jpeg,image/png,image/webp,image/gif"
          capture="user"
          class="hidden"
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
