<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { ApiRequestError } from '@/types/api'

import { schoolsApi, type School } from '../api'

/** Name and contact details. The slug is shown, never editable. */
const props = defineProps<{ school: School; canWrite: boolean }>()
const emit = defineEmits<{ saved: [school: School] }>()

const { t } = useI18n()

const form = ref({ name: '', name_en: '', email: '', phone: '', address: '', timezone: '' })
const saving = ref(false)
const saved = ref(false)
const error = ref<ApiRequestError | null>(null)

watch(
  () => props.school,
  (s) => {
    form.value = { name: s.name, name_en: s.name_en ?? '', email: s.email ?? '', phone: s.phone ?? '', address: s.address ?? '', timezone: s.timezone }
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
      </fieldset>
      <BaseButton v-if="canWrite" type="submit" :loading="saving">{{ t('bo.schools.saveProfile') }}</BaseButton>
    </form>
  </section>
</template>
