<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { ApiRequestError } from '@/types/api'

import { COPY_PARTS, organizationTypeName, organizationTypesApi, schoolsApi, type CopyPart, type OrganizationType, type PlacementMode, type School, type ServerLogin } from '../api'
import PlacementChoice from './PlacementChoice.vue'
import ServerFields from './ServerFields.vue'

/** New school: the essentials only. Setup runs in the background once saved. */
defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; created: [school: School] }>()

const { t, locale } = useI18n()

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
  // The parts of it to copy; all by default.
  copy_setup_parts: Object.keys(COPY_PARTS) as CopyPart[],
  // School, Company, Organization… ('' = School).
  organization_type_id: '',
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
/** The active organization types; School is the default. */
const orgTypes = ref<OrganizationType[]>([])
onMounted(async () => {
  try {
    orgTypes.value = (await organizationTypesApi.list()).filter((o) => o.status === 'active')
    const school = orgTypes.value.find((o) => o.code === 'school')
    if (school && !form.value.organization_type_id) form.value.organization_type_id = String(school.id)
  } catch {
    orgTypes.value = []
  }
})
const orgTypeOptions = computed(() => orgTypes.value.map((o) => ({ value: String(o.id), label: organizationTypeName(o, locale.value) })))

const allParts = Object.keys(COPY_PARTS) as CopyPart[]
/** Ticking a part ticks what it needs; unticking one unticks what needs it. */
function togglePart(part: CopyPart, on: boolean): void {
  const picked = new Set(form.value.copy_setup_parts)
  const add = (p: CopyPart): void => {
    picked.add(p)
    COPY_PARTS[p].forEach((n) => add(n as CopyPart))
  }
  const drop = (p: CopyPart): void => {
    picked.delete(p)
    allParts.filter((o) => picked.has(o) && (COPY_PARTS[o] as readonly string[]).includes(p)).forEach(drop)
  }
  if (on) add(part)
  else drop(part)
  form.value.copy_setup_parts = allParts.filter((p) => picked.has(p))
}
const needsLabel = (part: CopyPart) => COPY_PARTS[part].map((n) => t(`bo.schools.copyShort.${n}`)).join(', ')

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
  form.value.organization_type_id = String(orgTypes.value.find((o) => o.code === 'school')?.id ?? '')
  slugTouched.value = false
  error.value = null
}

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  try {
    const { server, copy_setup_from, copy_setup_parts, organization_type_id, ...rest } = form.value
    if (copy_setup_from && copy_setup_parts.length === 0) {
      error.value = new ApiRequestError(t('bo.schools.copyPickOne'), 422)
      return
    }
    const school = await schoolsApi.create({
      ...rest,
      name_en: rest.name_en || null,
      copy_setup_from: copy_setup_from ? Number(copy_setup_from) : null,
      // All ticked: send nothing (= everything).
      copy_setup_parts: copy_setup_from && copy_setup_parts.length < allParts.length ? copy_setup_parts : null,
      organization_type_id: organization_type_id ? Number(organization_type_id) : null,
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
      <BaseSelect
        v-if="orgTypeOptions.length"
        v-model="form.organization_type_id"
        :label="t('bo.schools.organizationType')"
        :options="orgTypeOptions"
        :hint="t('bo.schools.organizationTypeHint')"
        :error="error?.fieldError('organization_type_id')"
      />
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
        <div v-if="form.copy_setup_from" class="mt-4">
          <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-medium text-neutral-700">{{ t('bo.schools.copyWhat') }}</p>
            <div class="flex gap-3 text-sm">
              <button type="button" class="text-primary-700 hover:underline" @click="form.copy_setup_parts = [...allParts]">{{ t('bo.schools.copyAll') }}</button>
              <button type="button" class="text-primary-700 hover:underline" @click="form.copy_setup_parts = []">{{ t('bo.schools.copyNoneTicked') }}</button>
            </div>
          </div>
          <div class="grid gap-2 sm:grid-cols-2">
            <label
              v-for="part in allParts"
              :key="part"
              class="flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2 text-sm"
              :class="form.copy_setup_parts.includes(part) ? 'border-primary-500 bg-primary-50 text-neutral-900' : 'border-neutral-200 text-neutral-600'"
            >
              <input
                type="checkbox"
                class="mt-0.5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                :checked="form.copy_setup_parts.includes(part)"
                @change="togglePart(part, ($event.target as HTMLInputElement).checked)"
              />
              <span>
                {{ t(`bo.schools.copyParts.${part}`) }}
                <span v-if="COPY_PARTS[part].length" class="block text-xs text-neutral-500">{{ t('bo.schools.copyNeeds', { parts: needsLabel(part) }) }}</span>
              </span>
            </label>
          </div>
          <p v-if="error?.fieldError('copy_setup_parts')" class="mt-1 text-xs text-red-600">{{ error.fieldError('copy_setup_parts') }}</p>
        </div>
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
