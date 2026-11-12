<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { ApiRequestError } from '@/types/api'

import { dataApi, type PlatformDatabase } from '../api'

/**
 * Use for a school: put a ready "Other" database (usually a restored backup)
 * in place of a school's own database. The school is backed up and suspended
 * meanwhile; its old database is kept as <name>_before_restore.
 */
const props = defineProps<{ modelValue: boolean; database: PlatformDatabase | null; databases: PlatformDatabase[] }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; started: [school: string] }>()

const { t } = useI18n()

const schoolId = ref('')
const typed = ref('')
const errors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const busy = ref(false)

/** Schools with their own database on this server — the only ones a database can be put in place for. */
const schools = computed(() =>
  props.databases.filter((d) => d.kind === 'school' && d.school && d.placement === 'dedicated' && !d.server && d.exists),
)
const options = computed(() => schools.value.map((d) => ({ value: String(d.school!.id), label: `${d.school!.name} — ${d.database}` })))
const target = computed(() => schools.value.find((d) => String(d.school!.id) === schoolId.value) ?? null)
const keptAs = computed(() => (target.value ? `${target.value.database.slice(0, 48)}_before_restore` : ''))
const isUndo = computed(() => !!props.database && props.database.database === keptAs.value)
const matches = computed(() => !!target.value && typed.value.trim() === target.value.database)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    typed.value = ''
    errors.value = {}
    error.value = null
    // A restored backup or a kept copy names the school's database it came from.
    const from = props.database?.extra?.source_database
    const guess = schools.value.find((d) => d.database === from)
    schoolId.value = guess ? String(guess.school!.id) : ''
  },
)

async function start(): Promise<void> {
  if (!props.database?.extra || !target.value || !matches.value) return
  busy.value = true
  errors.value = {}
  error.value = null
  try {
    await dataApi.useForSchool(props.database.extra.id, target.value.school!.id, typed.value.trim())
    emit('started', target.value.school!.name)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('bo.data.swap.title')" @update:model-value="emit('update:modelValue', $event)">
    <form v-if="database" class="space-y-4" @submit.prevent="start">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>
      <p class="text-sm text-neutral-700">
        {{ t('bo.data.swap.intro') }} <span class="font-mono font-medium break-all">{{ database.database }}</span>
      </p>

      <BaseSelect
        v-model="schoolId"
        required
        :options="options"
        :label="t('bo.data.swap.school')"
        :placeholder="options.length ? t('bo.data.swap.pickSchool') : t('bo.data.swap.noSchools')"
        :error="errors.tenant_id?.[0] ?? errors.database?.[0]"
      />

      <div v-if="target" class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-700">
        <p class="mb-1 font-medium text-neutral-800">{{ t('bo.data.swap.whatHappens') }}</p>
        <ol class="list-decimal space-y-0.5 pl-5">
          <li>{{ t('bo.data.swap.steps.check', { name: target.school!.name }) }}</li>
          <li>{{ t('bo.data.swap.steps.suspend') }}</li>
          <li>{{ t('bo.data.swap.steps.backup', { db: target.database }) }}</li>
          <li class="break-all">{{ t('bo.data.swap.steps.swap', { live: target.database, kept: keptAs, restored: database.database }) }}</li>
          <li>{{ t('bo.data.swap.steps.migrate') }}</li>
          <li>{{ t('bo.data.swap.steps.reactivate') }}</li>
        </ol>
        <p v-if="isUndo" class="mt-2 text-xs text-neutral-600">{{ t('bo.data.swap.undoNote') }}</p>
        <p class="mt-2 text-xs text-neutral-500">{{ t('bo.data.swap.failNote') }}</p>
      </div>

      <BaseAlert v-if="target" variant="warning">{{ t('bo.data.swap.warning', { name: target.school!.name }) }}</BaseAlert>
      <BaseInput
        v-if="target"
        v-model="typed"
        :label="t('bo.data.swap.typeName', { name: target.database })"
        :placeholder="target.database"
        autocomplete="off"
        :error="errors.confirm_name?.[0]"
      />
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton variant="danger" :disabled="!matches" :loading="busy" @click="start">{{ t('bo.data.swap.confirm') }}</BaseButton>
    </template>
  </BaseModal>
</template>
