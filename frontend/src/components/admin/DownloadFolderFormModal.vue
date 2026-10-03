<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { downloadsService, type DownloadFolder, type DownloadFolderStatus } from '@/services/downloads'
import { ApiRequestError } from '@/types/api'

/** Create or edit one Upload-menu folder — see Uploads.vue. */
const props = defineProps<{
  modelValue: boolean
  /** Present when editing. */
  folder?: DownloadFolder | null
  /** Creating a sub-folder inside this top folder. */
  parent?: DownloadFolder | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [folder: DownloadFolder] }>()

const { t } = useI18n()

const isEditing = computed(() => props.folder != null)

const title = computed(() => {
  if (isEditing.value) return t('admin.uploads.editFolder')
  return props.parent ? t('admin.uploads.newSubFolderIn', { name: props.parent.name }) : t('admin.uploads.newFolder')
})

const form = reactive({ name: '', sort_order: 0, status: 'active' as DownloadFolderStatus })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const statusOptions = computed(() => [
  { value: 'active', label: t('admin.uploads.statusShown') },
  { value: 'inactive', label: t('admin.uploads.statusHidden') },
])

watch(
  () => [props.modelValue, props.folder] as const,
  ([open]) => {
    if (!open) return

    form.name = props.folder?.name ?? ''
    form.sort_order = props.folder?.sort_order ?? 0
    form.status = props.folder?.status ?? 'active'
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const saved = isEditing.value
      ? await downloadsService.updateFolder(props.folder!.id, { ...form })
      : await downloadsService.createFolder({ ...form, parent_id: props.parent?.id ?? null })

    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.uploads.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="title" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <BaseAlert v-if="errors.parent_id?.[0]" variant="danger">{{ errors.parent_id[0] }}</BaseAlert>

      <BaseInput v-model="form.name" :label="t('admin.uploads.folderName')" :error="errors.name?.[0]" required />

      <div class="grid grid-cols-2 gap-4">
        <BaseInput
          :model-value="String(form.sort_order)"
          type="number"
          :label="t('admin.uploads.sortOrder')"
          :error="errors.sort_order?.[0]"
          @update:model-value="form.sort_order = Number($event) || 0"
        />
        <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.uploads.status')" />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
