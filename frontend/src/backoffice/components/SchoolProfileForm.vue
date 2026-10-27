<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { ApiRequestError } from '@/types/api'

import { organizationTypeName, organizationTypesApi, schoolsApi, type OrganizationType, type School } from '../api'

/** Name and contact details. The slug is shown, never editable. */
const props = defineProps<{ school: School; canWrite: boolean }>()
const emit = defineEmits<{ saved: [school: School] }>()

const { t, locale } = useI18n()

const form = ref({ name: '', name_en: '', email: '', phone: '', address: '', timezone: '', organization_type_id: '' })

/** School, Company, Organization…: active types, plus the one it has now. */
const orgTypes = ref<OrganizationType[]>([])
onMounted(async () => {
  try {
    orgTypes.value = await organizationTypesApi.list()
    if (!form.value.organization_type_id) form.value.organization_type_id = String(orgTypes.value.find((o) => o.code === 'school')?.id ?? '')
  } catch {
    orgTypes.value = []
  }
})
const orgTypeOptions = computed(() =>
  orgTypes.value
    .filter((o) => o.status === 'active' || String(o.id) === form.value.organization_type_id)
    .map((o) => ({ value: String(o.id), label: organizationTypeName(o, locale.value) })),
)
const saving = ref(false)
const saved = ref(false)
const error = ref<ApiRequestError | null>(null)

watch(
  () => props.school,
  (s) => {
    form.value = {
      name: s.name,
      name_en: s.name_en ?? '',
      email: s.email ?? '',
      phone: s.phone ?? '',
      address: s.address ?? '',
      timezone: s.timezone,
      organization_type_id: s.organization_type_id ? String(s.organization_type_id) : String(orgTypes.value.find((o) => o.code === 'school')?.id ?? ''),
    }
  },
  { immediate: true },
)

async function save(): Promise<void> {
  saving.value = true
  saved.value = false
  error.value = null
  const v = form.value
  try {
    const school = await schoolsApi.update(props.school.id, {
      name: v.name,
      name_en: v.name_en || null,
      email: v.email || null,
      phone: v.phone || null,
      address: v.address || null,
      timezone: v.timezone,
      ...(v.organization_type_id ? { organization_type_id: Number(v.organization_type_id) } : {}),
    })
    saved.value = true
    emit('saved', school)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
    <h2 class="mb-3 font-semibold text-neutral-900">{{ t('bo.schools.profile') }}</h2>
    <BaseAlert v-if="saved" variant="success" class="mb-3">{{ t('bo.common.saved') }}</BaseAlert>
    <BaseAlert v-if="error && !error.errors" variant="danger" class="mb-3">{{ error.message }}</BaseAlert>

    <dl class="mb-4 grid gap-2 text-sm sm:grid-cols-2">
      <div>
        <dt class="text-xs text-neutral-500">{{ t('bo.schools.subdomain') }}</dt>
        <dd class="font-mono break-all text-neutral-800">{{ school.subdomain }}</dd>
      </div>
      <div>
        <dt class="text-xs text-neutral-500">{{ t('bo.schools.database') }}</dt>
        <dd class="font-mono text-neutral-800">{{ school.database }}</dd>
      </div>
    </dl>

    <form class="space-y-4" @submit.prevent="save">
      <fieldset :disabled="!canWrite" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.name" :label="t('bo.schools.name')" :error="error?.fieldError('name')" required />
          <BaseInput v-model="form.name_en" :label="t('bo.schools.nameEn')" :error="error?.fieldError('name_en')" />
          <BaseInput v-model="form.email" :label="t('bo.schools.email')" type="email" :error="error?.fieldError('email')" />
          <BaseInput v-model="form.phone" :label="t('bo.schools.phone')" :error="error?.fieldError('phone')" />
        </div>
        <BaseInput v-model="form.address" :label="t('bo.schools.address')" :error="error?.fieldError('address')" />
        <BaseInput v-model="form.timezone" :label="t('bo.schools.timezone')" :error="error?.fieldError('timezone')" />
        <BaseSelect
          v-if="orgTypeOptions.length"
          v-model="form.organization_type_id"
          :label="t('bo.schools.organizationType')"
          :options="orgTypeOptions"
          :hint="t('bo.schools.organizationTypeHint')"
          :error="error?.fieldError('organization_type_id')"
        />
      </fieldset>
      <BaseButton v-if="canWrite" type="submit" :loading="saving">{{ t('bo.schools.saveProfile') }}</BaseButton>
    </form>
  </section>
</template>
