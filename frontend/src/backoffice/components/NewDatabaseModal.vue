<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { ApiRequestError } from '@/types/api'

import { dataApi, type PlatformDatabase } from '../api'

/**
 * Data ops > Databases > New database: an "Other" database on this server —
 * empty, a copy of the school template (every school table, no rows), or a
 * copy of one of our databases. Made in the background.
 */
const props = defineProps<{ modelValue: boolean; databases: PlatformDatabase[]; copyFrom?: string | null }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; created: [name: string] }>()

const { t } = useI18n()

type Source = 'empty' | 'template' | 'copy'
const form = reactive({ name: '', source: 'empty' as Source, source_database: '', note: '' })
const errors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const busy = ref(false)

/** Ours, on this server, existing and finished. */
const copyOptions = computed(() =>
  props.databases
    .filter((d) => d.exists && !d.server && (d.kind !== 'extra' || d.extra?.status === 'ready'))
    .map((d) => ({ value: d.database, label: d.school ? `${d.database} — ${d.school.name}` : d.database })),
)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    form.name = props.copyFrom ? `${props.copyFrom}_copy` : ''
    form.source = props.copyFrom ? 'copy' : 'empty'
    form.source_database = props.copyFrom ?? ''
    form.note = ''
    errors.value = {}
    error.value = null
  },
)

const SOURCES: Source[] = ['empty', 'template', 'copy']

async function save(): Promise<void> {
  busy.value = true
  errors.value = {}
  error.value = null
  try {
    const name = form.name.trim().toLowerCase()
    await dataApi.newDatabase({ name, source: form.source, source_database: form.source === 'copy' ? form.source_database : null, note: form.note.trim() || null })
    emit('created', name)
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
  <BaseModal :model-value="modelValue" :title="t('bo.data.newDb.title')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>
      <BaseInput v-model="form.name" required :label="t('bo.data.newDb.name')" :hint="t('bo.data.newDb.nameHint')" placeholder="tn_newtech_test" :error="errors.name?.[0]" />

      <div>
        <p class="mb-1 text-sm font-medium text-neutral-700">{{ t('bo.data.newDb.source') }}</p>
        <div class="space-y-2">
          <label
            v-for="source in SOURCES"
            :key="source"
            class="flex cursor-pointer items-start gap-2 rounded-lg border p-3"
            :class="form.source === source ? 'border-primary-500 bg-primary-50' : 'border-neutral-200'"
          >
            <input v-model="form.source" type="radio" :value="source" class="mt-0.5 border-neutral-300 text-primary-600 focus:ring-primary-500" />
            <span>
              <span class="block text-sm font-medium text-neutral-800">{{ t(`bo.data.newDb.sources.${source}`) }}</span>
              <span class="block text-xs text-neutral-500">{{ t(`bo.data.newDb.sourceHints.${source}`) }}</span>
            </span>
          </label>
        </div>
      </div>

      <BaseSelect
        v-if="form.source === 'copy'"
        v-model="form.source_database"
        required
        :options="copyOptions"
        :label="t('bo.data.newDb.copyOf')"
        :placeholder="t('bo.data.newDb.pickDatabase')"
        :error="errors.source_database?.[0]"
      />
      <BaseInput v-model="form.note" :label="t('bo.data.newDb.note')" :placeholder="t('bo.data.newDb.notePlaceholder')" :error="errors.note?.[0]" />
      <p class="text-xs text-neutral-500">{{ t('bo.data.newDb.background') }}</p>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton :loading="busy" @click="save">{{ t('bo.data.newDb.create') }}</BaseButton>
    </template>
  </BaseModal>
</template>
