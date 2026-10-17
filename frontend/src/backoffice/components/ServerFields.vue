<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { ApiRequestError } from '@/types/api'

import { schoolsApi, type ConnectionTest, type ServerLogin } from '../api'

/**
 * A separate PostgreSQL server's login, as staff type it, with "Test
 * connection". The password is sent only to test or to save — never shown back.
 */
const model = defineModel<ServerLogin>({ required: true })
defineProps<{ errors?: ApiRequestError | null }>()

const { t } = useI18n()
const testing = ref(false)
const result = ref<ConnectionTest | null>(null)

const sslOptions = ['disable', 'prefer', 'require', 'verify-ca', 'verify-full'].map((v) => ({ value: v, label: v }))

function set<K extends keyof ServerLogin>(key: K, value: ServerLogin[K]): void {
  model.value = { ...model.value, [key]: value }
  result.value = null
}

async function test(): Promise<void> {
  testing.value = true
  result.value = null
  try {
    result.value = await schoolsApi.testConnection({ ...model.value, port: Number(model.value.port) })
  } catch (e) {
    result.value = { ok: false, message: e instanceof ApiRequestError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.message) : t('bo.common.failed') }
  } finally {
    testing.value = false
  }
}
</script>

<template>
  <div class="space-y-3 rounded-lg border border-neutral-200 bg-neutral-50 p-3">
    <div class="grid gap-3 sm:grid-cols-3">
      <div class="sm:col-span-2">
        <BaseInput :model-value="model.host" :label="t('bo.placement.host')" placeholder="db.example.com" required @update:model-value="set('host', $event)" />
      </div>
      <BaseInput :model-value="String(model.port)" type="number" :label="t('bo.placement.port')" required @update:model-value="set('port', Number($event))" />
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
      <BaseInput :model-value="model.database" :label="t('bo.placement.database')" required @update:model-value="set('database', $event)" />
      <BaseInput :model-value="model.username" :label="t('bo.placement.username')" autocomplete="off" required @update:model-value="set('username', $event)" />
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
      <BaseInput :model-value="model.password" type="password" :label="t('bo.placement.password')" autocomplete="new-password" required @update:model-value="set('password', $event)" />
      <BaseSelect :model-value="model.sslmode" :label="t('bo.placement.sslmode')" :options="sslOptions" @update:model-value="set('sslmode', $event)" />
    </div>
    <p v-if="errors?.fieldError('server')" class="text-sm text-red-700">{{ errors.fieldError('server') }}</p>
    <div class="flex flex-wrap items-center gap-3">
      <BaseButton size="sm" variant="outline" :loading="testing" @click="test">{{ t('bo.placement.test') }}</BaseButton>
      <span v-if="result" class="text-sm" :class="result.ok ? 'text-green-700' : 'text-red-700'">{{ result.message }}</span>
    </div>
  </div>
</template>
