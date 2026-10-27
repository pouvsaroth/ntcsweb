<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { ApiRequestError } from '@/types/api'

import { schoolsApi, type PlacementMode, type School, type ServerLogin } from '../api'
import PlacementChoice from './PlacementChoice.vue'
import ServerFields from './ServerFields.vue'

/** New school: the essentials only. Setup runs in the background once saved. */
defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; created: [school: School] }>()

const { t } = useI18n()

const blank = () => ({
  name: '',
  name_en: '',
  slug: '',
  owner_name: '',
  owner_email: '',
  timezone: 'Asia/Phnom_Penh',
  locale: 'km',
  default_currency: 'USD' as 'USD' | 'KHR',
  // Shared unless staff choose otherwise (stage 4, as SmartGPS).
  placement: 'shared' as PlacementMode,
  server: { host: '', port: 5432, database: '', username: '', password: '', sslmode: 'require' } as ServerLogin,
  // '' = start empty; otherwise a school id whose setup is copied.
  copy_setup_from: '',
})
const form = ref(blank())
const slugTouched = ref(false)
const saving = ref(false)
const error = ref<ApiRequestError | null>(null)

/** Suggest a slug from the English name until the slug is typed by hand. */
watch(
  () => form.value.name_en,
  (name) => {
    if (slugTouched.value) return
    form.value.slug = name
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 32)
  },
)

/** Schools whose setup can be copied: the active ones. */
const sourceSchools = ref<School[]>([])
onMounted(async () => {
  try {
    sourceSchools.value = (await schoolsApi.list({ page: 1, per_page: 200, filter: { status: 'active' } })).data
  } catch {
    sourceSchools.value = []
  }
})
const copyOptions = computed(() => [
  { value: '', label: t('bo.schools.copyNone') },
  ...sourceSchools.value.map((s) => ({ value: String(s.id), label: s.name_en ? `${s.name} (${s.name_en})` : s.name })),
])

const slugHint = computed(() =>
  t('bo.schools.slugHint', { sub: `${form.value.slug || '…'}.ntcsweb.com` }),
)

function onSlug(value: string): void {
  slugTouched.value = true
  form.value.slug = value.toLowerCase()
}

function close(): void {
  emit('update:modelValue', false)
  form.value = blank()
  slugTouched.value = false
  error.value = null
}

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  try {
    const { server, copy_setup_from, ...rest } = form.value
    const school = await schoolsApi.create({
      ...rest,
      name_en: rest.name_en || null,
      copy_setup_from: copy_setup_from ? Number(copy_setup_from) : null,
      server: rest.placement === 'separate' ? { ...server, port: Number(server.port) } : undefined,
    })
    emit('created', school)
    close()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('bo.schools.add')" size="lg" @update:model-value="close">
    <form id="bo-school-create" class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="error && !error.errors" variant="danger">{{ error.message }}</BaseAlert>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.name" :label="t('bo.schools.name')" :error="error?.fieldError('name')" required />
        <BaseInput v-model="form.name_en" :label="t('bo.schools.nameEn')" :error="error?.fieldError('name_en')" />
      </div>
      <BaseInput
        :model-value="form.slug"
        :label="t('bo.schools.slug')"
        :hint="slugHint"
        :error="error?.fieldError('slug')"
        required
        @update:model-value="onSlug"
      />

      <fieldset class="rounded-lg border border-neutral-200 p-4">
        <legend class="px-1 text-sm font-medium text-neutral-700">{{ t('bo.schools.owner') }}</legend>
        <p class="mb-3 text-xs text-neutral-500">{{ t('bo.schools.ownerHint') }}</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.owner_name" :label="t('bo.schools.ownerName')" :error="error?.fieldError('owner_name')" required />
          <BaseInput
            v-model="form.owner_email"
            :label="t('bo.schools.ownerEmail')"
            type="email"
            :error="error?.fieldError('owner_email')"
            required
          />
        </div>
      </fieldset>

      <fieldset class="rounded-lg border border-neutral-200 p-4">
        <legend class="px-1 text-sm font-medium text-neutral-700">{{ t('bo.schools.copyTitle') }}</legend>
        <BaseSelect
          v-model="form.copy_setup_from"
          :label="t('bo.schools.copyFrom')"
          :options="copyOptions"
          :hint="t('bo.schools.copyHint')"
          :error="error?.fieldError('copy_setup_from')"
        />
      </fieldset>

      <fieldset class="space-y-3 rounded-lg border border-neutral-200 p-4">
        <legend class="px-1 text-sm font-medium text-neutral-700">{{ t('bo.placement.title') }}</legend>
        <PlacementChoice v-model="form.placement" />
        <ServerFields v-if="form.placement === 'separate'" v-model="form.server" :errors="error" />
      </fieldset>

      <fieldset class="rounded-lg border border-neutral-200 p-4">
        <legend class="px-1 text-sm font-medium text-neutral-700">{{ t('bo.schools.settings') }}</legend>
        <div class="grid gap-4 sm:grid-cols-3">
          <BaseInput v-model="form.timezone" :label="t('bo.schools.timezone')" :error="error?.fieldError('timezone')" />
          <BaseSelect
            v-model="form.locale"
            :label="t('bo.schools.locale')"
            :options="[
              { value: 'km', label: 'ភាសាខ្មែរ' },
              { value: 'en', label: 'English' },
              { value: 'zh', label: '中文' },
              { value: 'ko', label: '한국어' },
              { value: 'ja', label: '日本語' },
            ]"
          />
          <BaseSelect
            :model-value="form.default_currency"
            :label="t('bo.schools.currency')"
            :options="[
              { value: 'USD', label: 'USD' },
              { value: 'KHR', label: 'KHR' },
            ]"
            @update:model-value="form.default_currency = $event as 'USD' | 'KHR'"
          />
        </div>
      </fieldset>
    </form>

    <template #footer>
      <BaseButton variant="ghost" @click="close">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="bo-school-create" :loading="saving">{{ t('bo.schools.create') }}</BaseButton>
    </template>
  </BaseModal>
</template>
