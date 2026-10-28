<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { frontDeskService, type Visitor, type VisitorInput } from '@/services/frontDesk'
import type { StaffOption } from '@/services/inquiries'
import { ApiRequestError } from '@/types/api'

/**
 * Check a walk-in in (now), or edit a visitor record — including correcting
 * the check-in / check-out times.
 */
const props = defineProps<{ modelValue: boolean; visitor: Visitor | null; staff: StaffOption[] }>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; saved: [Visitor] }>()

const { t } = useI18n()

const pad = (n: number) => String(n).padStart(2, '0')
/** ISO timestamp → the local "YYYY-MM-DDTHH:mm" a date-time box holds. */
function toLocalInput(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}
/** Back to an ISO timestamp with the viewer's offset, so the server stores the right moment. */
const toIso = (local: string) => new Date(local).toISOString()

const form = reactive({
  name: '',
  phone: '',
  id_number: '',
  organization: '',
  purpose: '',
  host_staff_id: '',
  people_count: '1',
  badge_no: '',
  note: '',
  check_in_at: '',
  check_out_at: '',
})
const errors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const saving = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    const v = props.visitor
    Object.assign(form, {
      name: v?.name ?? '',
      phone: v?.phone ?? '',
      id_number: v?.id_number ?? '',
      organization: v?.organization ?? '',
      purpose: v?.purpose ?? '',
      host_staff_id: v?.host_staff_id ? String(v.host_staff_id) : '',
      people_count: String(v?.people_count ?? 1),
      badge_no: v?.badge_no ?? '',
      note: v?.note ?? '',
      check_in_at: toLocalInput(v?.check_in_at ?? null),
      check_out_at: toLocalInput(v?.check_out_at ?? null),
    })
    errors.value = {}
    error.value = null
  },
)

const hostOptions = computed(() => [
  { value: '', label: '—' },
  ...props.staff.map((s) => ({ value: String(s.id), label: s.name, hint: s.employee_code ?? undefined })),
])

async function save() {
  saving.value = true
  errors.value = {}
  error.value = null
  const input: VisitorInput = {
    name: form.name.trim(),
    phone: form.phone.trim() || null,
    id_number: form.id_number.trim() || null,
    organization: form.organization.trim() || null,
    purpose: form.purpose.trim(),
    host_staff_id: form.host_staff_id ? Number(form.host_staff_id) : null,
    people_count: Math.max(1, Number(form.people_count) || 1),
    badge_no: form.badge_no.trim() || null,
    note: form.note.trim() || null,
  }
  try {
    let saved: Visitor
    if (props.visitor) {
      saved = await frontDeskService.updateVisitor(props.visitor.id, {
        ...input,
        ...(form.check_in_at ? { check_in_at: toIso(form.check_in_at) } : {}),
        check_out_at: form.check_out_at ? toIso(form.check_out_at) : null,
      })
    } else {
      saved = await frontDeskService.createVisitor(input)
    }
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else error.value = e instanceof ApiRequestError ? e.message : t('frontOffice.visitors.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    size="lg"
    :title="visitor ? t('frontOffice.visitors.editTitle') : t('frontOffice.visitors.addTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form id="visitor-form" class="space-y-4" novalidate @submit.prevent="save">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>
      <p v-if="!visitor" class="text-sm text-neutral-500">{{ t('frontOffice.visitors.walkInLede') }}</p>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.name" required :label="t('frontOffice.inquiries.name')" :error="errors.name?.[0]" />
        <BaseInput v-model="form.phone" type="tel" :label="t('common.phone')" :error="errors.phone?.[0]" />
        <BaseInput v-model="form.organization" :label="t('frontOffice.appointments.organization')" :error="errors.organization?.[0]" />
        <BaseInput v-model="form.id_number" :label="t('frontOffice.visitors.idNumber')" :error="errors.id_number?.[0]" />
        <div class="sm:col-span-2">
          <BaseInput v-model="form.purpose" required :label="t('frontOffice.appointments.purpose')" :placeholder="t('frontOffice.appointments.purposePlaceholder')" :error="errors.purpose?.[0]" />
        </div>
        <SearchableSelect v-model="form.host_staff_id" :options="hostOptions" :label="t('frontOffice.appointments.host')" :error="errors.host_staff_id?.[0]" />
        <div class="grid grid-cols-2 gap-4">
          <BaseInput v-model="form.people_count" type="number" :label="t('frontOffice.visitors.people')" :error="errors.people_count?.[0]" />
          <BaseInput v-model="form.badge_no" :label="t('frontOffice.visitors.badge')" :error="errors.badge_no?.[0]" />
        </div>
        <!-- Date + time boxes need the full width. -->
        <template v-if="visitor">
          <div class="sm:col-span-2">
            <BaseInput v-model="form.check_in_at" type="datetime-local" required :label="t('frontOffice.visitors.checkIn')" :error="errors.check_in_at?.[0]" />
          </div>
          <div class="sm:col-span-2">
            <BaseInput v-model="form.check_out_at" type="datetime-local" :label="t('frontOffice.visitors.checkOut')" :error="errors.check_out_at?.[0]" />
          </div>
        </template>
      </div>

      <div>
        <label for="visitor-note" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('frontOffice.inquiries.note') }}</label>
        <textarea
          id="visitor-note"
          v-model="form.note"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="visitor-form" :loading="saving">{{ visitor ? t('common.save') : t('frontOffice.visitors.checkInNow') }}</BaseButton>
    </template>
  </BaseModal>
</template>
