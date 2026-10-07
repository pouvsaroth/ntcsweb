<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { staffPayrollProfilesService, type StaffProfileRow } from '@/services/payroll'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/**
 * Each working staff member's payroll profile — on the Tax tab, tax
 * residence and dependants; on the Social security tab, NSSF enrolment and
 * number. One profile per staff member behind both.
 */
const props = defineProps<{ mode: 'tax' | 'nssf' }>()

const { t } = useI18n()
const auth = useAuthStore()
const canManage = computed(() => auth.can('payroll.manage'))

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<StaffProfileRow>((query) => staffPayrollProfilesService.list(query))

function summary(row: StaffProfileRow): string {
  const p = row.profile
  if (props.mode === 'nssf') return p.social_security_enrolled ? (p.social_security_number ?? t('admin.payroll.profiles.noNumber')) : t('admin.payroll.profiles.notEnrolled')
  if (!p.tax_resident) return t('admin.payroll.profiles.nonResident')
  return [
    t('admin.payroll.profiles.resident'),
    p.spouse_dependent ? t('admin.payroll.profiles.spouse') : null,
    p.child_dependents > 0 ? t('admin.payroll.profiles.childrenN', { count: p.child_dependents }) : null,
  ]
    .filter(Boolean)
    .join(' · ')
}

const editing = ref<StaffProfileRow | null>(null)
const form = reactive({ tax_resident: true, spouse_dependent: false, child_dependents: '0', social_security_enrolled: true, social_security_number: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

function open(row: StaffProfileRow) {
  editing.value = row
  form.tax_resident = row.profile.tax_resident
  form.spouse_dependent = row.profile.spouse_dependent
  form.child_dependents = String(row.profile.child_dependents)
  form.social_security_enrolled = row.profile.social_security_enrolled
  form.social_security_number = row.profile.social_security_number ?? ''
  errors.value = {}
  saveError.value = null
}

async function save() {
  if (!editing.value) return
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    await staffPayrollProfilesService.update(
      editing.value.staff.id,
      props.mode === 'tax'
        ? { tax_resident: form.tax_resident, spouse_dependent: form.spouse_dependent, child_dependents: Number(form.child_dependents || 0) }
        : { social_security_enrolled: form.social_security_enrolled, social_security_number: form.social_security_number.trim() || null },
    )
    editing.value = null
    await fetch()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.saveFailed')
  } finally {
    saving.value = false
  }
}

onMounted(() => fetch())
</script>

<template>
  <section>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-sm font-semibold text-neutral-800">{{ t(mode === 'tax' ? 'admin.payroll.profiles.taxTitle' : 'admin.payroll.profiles.nssfTitle') }}</h2>
        <p class="text-sm text-neutral-500">{{ t(mode === 'tax' ? 'admin.payroll.profiles.taxHint' : 'admin.payroll.profiles.nssfHint') }}</p>
      </div>
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-3">{{ error }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-8"><BaseSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('admin.payroll.salaries.empty') }}</p>
    <ul v-else class="divide-y divide-neutral-100 rounded-[--radius-card] border border-neutral-200 bg-white">
      <li v-for="row in items" :key="row.staff.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5">
        <div class="min-w-0">
          <p class="text-sm font-medium text-neutral-800">{{ row.staff.name }} <span class="font-normal text-neutral-500">({{ row.staff.employee_code }})</span></p>
          <p class="text-xs text-neutral-600">
            {{ summary(row) }}
            <BaseBadge v-if="!row.profile.saved" variant="neutral" class="ml-1">{{ t('admin.payroll.profiles.default') }}</BaseBadge>
          </p>
        </div>
        <button v-if="canManage" type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="open(row)">{{ t('admin.payroll.edit') }}</button>
      </li>
    </ul>
    <BasePagination v-if="meta" :meta="meta" class="mt-3" @update:page="setPage" />

    <BaseModal :model-value="editing !== null" :title="editing ? `${editing.staff.name} (${editing.staff.employee_code ?? '—'})` : ''" @update:model-value="editing = null">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <template v-if="mode === 'tax'">
          <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="form.tax_resident" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('admin.payroll.profiles.residentLabel') }}
          </label>
          <template v-if="form.tax_resident">
            <label class="flex items-center gap-2 text-sm text-neutral-700">
              <input v-model="form.spouse_dependent" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
              {{ t('admin.payroll.profiles.spouseLabel') }}
            </label>
            <BaseInput v-model="form.child_dependents" type="number" :label="t('admin.payroll.profiles.childrenLabel')" :error="errors.child_dependents?.[0]" />
          </template>
          <p v-else class="text-sm text-neutral-600">{{ t('admin.payroll.profiles.nonResidentHint') }}</p>
        </template>
        <template v-else>
          <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="form.social_security_enrolled" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('admin.payroll.profiles.enrolledLabel') }}
          </label>
          <BaseInput v-if="form.social_security_enrolled" v-model="form.social_security_number" :label="t('admin.payroll.profiles.numberLabel')" :error="errors.social_security_number?.[0]" />
        </template>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="editing = null">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </section>
</template>
