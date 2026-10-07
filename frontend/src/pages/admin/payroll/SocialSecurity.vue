<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import StaffProfileList from '@/pages/admin/payroll/StaffProfileList.vue'
import { payrollRulesService, riel, type SocialSecurityScheme } from '@/services/payroll'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Payroll > Social security — NSSF contributions as the school sets
 * them (Occupational risk, Health care, Pension: a % of the monthly wage
 * for the staff member and for the school, the wage counted between a
 * floor and a ceiling in riel), and who is enrolled. Cards on a phone, a
 * table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('payroll.manage'))

const schemes = ref<SocialSecurityScheme[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  try {
    schemes.value = (await payrollRulesService.get()).social_security_schemes
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.loadFailed')
  } finally {
    loading.value = false
  }
}

function wageRange(scheme: SocialSecurityScheme): string {
  return scheme.max_wage === null ? t('admin.payroll.tax.over', { amount: riel(scheme.min_wage) }) : `${riel(scheme.min_wage)} – ${riel(scheme.max_wage)}`
}

const formOpen = ref(false)
const editing = ref<SocialSecurityScheme | null>(null)
const form = reactive({ code: '', name: '', employee_rate: '', employer_rate: '', min_wage: '', max_wage: '', reduces_taxable: true, is_active: true })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

function open(scheme: SocialSecurityScheme | null) {
  editing.value = scheme
  form.code = scheme?.code ?? ''
  form.name = scheme?.name ?? ''
  form.employee_rate = String(scheme?.employee_rate ?? 0)
  form.employer_rate = String(scheme?.employer_rate ?? 0)
  form.min_wage = String(scheme?.min_wage ?? 0)
  form.max_wage = scheme?.max_wage === null || scheme === null ? '' : String(scheme.max_wage)
  form.reduces_taxable = scheme?.reduces_taxable ?? true
  form.is_active = scheme?.is_active ?? true
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    code: form.code,
    name: form.name,
    employee_rate: Number(form.employee_rate || 0),
    employer_rate: Number(form.employer_rate || 0),
    min_wage: Number(form.min_wage || 0),
    max_wage: form.max_wage === '' ? null : Number(form.max_wage),
    reduces_taxable: form.reduces_taxable,
    is_active: form.is_active,
  }
  try {
    const rules = editing.value ? await payrollRulesService.updateScheme(editing.value.id, input) : await payrollRulesService.createScheme(input)
    schemes.value = rules.social_security_schemes
    formOpen.value = false
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(scheme: SocialSecurityScheme) {
  if (!(await confirmDialog.confirm({ message: t('admin.payroll.components.deleteConfirm', { name: scheme.name }), danger: true }))) return
  error.value = null
  try {
    schemes.value = (await payrollRulesService.removeScheme(scheme.id)).social_security_schemes
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

onMounted(() => load())
</script>

<template>
  <div class="space-y-6">
    <section>
      <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.payroll.nssf.schemes') }}</h2>
          <p class="text-sm text-neutral-500">{{ t('admin.payroll.nssf.hint') }}</p>
        </div>
        <BaseButton v-if="canManage" @click="open(null)">{{ t('admin.payroll.nssf.add') }}</BaseButton>
      </div>

      <BaseAlert v-if="error" variant="danger" class="mb-3">{{ error }}</BaseAlert>
      <div v-if="loading" class="flex justify-center py-8"><BaseSpinner /></div>
      <p v-else-if="schemes.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('admin.payroll.nssf.empty') }}</p>
      <template v-else>
        <div class="space-y-2 sm:hidden">
          <div v-for="scheme in schemes" :key="scheme.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <p class="text-sm font-semibold text-neutral-800">{{ scheme.name }} <span class="font-normal text-neutral-500">({{ scheme.code }})</span></p>
              <BaseBadge :variant="scheme.is_active ? 'success' : 'neutral'">{{ scheme.is_active ? t('admin.organization.statusActive') : t('admin.organization.statusInactive') }}</BaseBadge>
            </div>
            <p class="text-sm text-neutral-700">{{ t('admin.payroll.nssf.staffShare') }} {{ scheme.employee_rate }}% · {{ t('admin.payroll.nssf.schoolShare') }} {{ scheme.employer_rate }}%</p>
            <p class="text-xs text-neutral-500">{{ wageRange(scheme) }}<template v-if="scheme.reduces_taxable"> · {{ t('admin.payroll.components.beforeTax') }}</template></p>
            <div v-if="canManage" class="mt-2 flex justify-end gap-3">
              <button type="button" class="text-sm font-medium text-primary-700" @click="open(scheme)">{{ t('admin.payroll.edit') }}</button>
              <button type="button" class="text-sm font-medium text-danger-600" @click="remove(scheme)">{{ t('admin.organization.delete') }}</button>
            </div>
          </div>
        </div>
        <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
          <table class="w-full text-left text-sm">
            <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-4 py-3 font-medium">{{ t('admin.payroll.components.name') }}</th>
                <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.nssf.staffShare') }}</th>
                <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.nssf.schoolShare') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.payroll.nssf.wageCounted') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.organization.status') }}</th>
                <th v-if="canManage" class="px-4 py-3 text-right font-medium">{{ t('admin.organization.actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="scheme in schemes" :key="scheme.id">
                <td class="px-4 py-3">
                  <p class="font-medium text-neutral-800">{{ scheme.name }}</p>
                  <p class="text-xs text-neutral-500">{{ scheme.code }}<template v-if="scheme.reduces_taxable"> · {{ t('admin.payroll.components.beforeTax') }}</template></p>
                </td>
                <td class="px-4 py-3 text-right tabular-nums">{{ scheme.employee_rate }}%</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ scheme.employer_rate }}%</td>
                <td class="px-4 py-3 text-neutral-700">{{ wageRange(scheme) }}</td>
                <td class="px-4 py-3">
                  <BaseBadge :variant="scheme.is_active ? 'success' : 'neutral'">{{ scheme.is_active ? t('admin.organization.statusActive') : t('admin.organization.statusInactive') }}</BaseBadge>
                </td>
                <td v-if="canManage" class="px-4 py-3">
                  <div class="flex justify-end gap-3">
                    <button type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="open(scheme)">{{ t('admin.payroll.edit') }}</button>
                    <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(scheme)">{{ t('admin.organization.delete') }}</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>

    <StaffProfileList mode="nssf" />

    <BaseModal v-model="formOpen" :title="editing ? t('admin.payroll.nssf.edit') : t('admin.payroll.nssf.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.code" required :label="t('admin.organization.code')" :error="errors.code?.[0]" />
          <BaseInput v-model="form.name" required :label="t('admin.payroll.components.name')" :error="errors.name?.[0]" />
          <BaseInput v-model="form.employee_rate" type="number" :label="t('admin.payroll.nssf.staffRate')" :error="errors.employee_rate?.[0]" />
          <BaseInput v-model="form.employer_rate" type="number" :label="t('admin.payroll.nssf.schoolRate')" :error="errors.employer_rate?.[0]" />
          <BaseInput v-model="form.min_wage" type="number" :label="t('admin.payroll.nssf.floor')" :error="errors.min_wage?.[0]" />
          <BaseInput v-model="form.max_wage" type="number" :label="t('admin.payroll.nssf.ceiling')" :placeholder="t('admin.payroll.tax.noTop')" :error="errors.max_wage?.[0]" />
        </div>
        <div class="space-y-2">
          <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="form.reduces_taxable" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('admin.payroll.nssf.reducesTaxable') }}
          </label>
          <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('admin.organization.statusActive') }}
          </label>
        </div>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
