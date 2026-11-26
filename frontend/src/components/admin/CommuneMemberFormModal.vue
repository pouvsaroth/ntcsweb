<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import PlaceSelects from '@/components/admin/PlaceSelects.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { communeMembersService, type CommuneMember, type MemberRole, type MemberStaffOption } from '@/services/communeMembers'
import type { PlacePick } from '@/services/partyBranches'
import { ApiRequestError } from '@/types/api'

/**
 * Political Party > Commune members: put a staff member on a commune's full
 * or reserve list. Their details come from HRM > Staff. No. empty = the end
 * of the list; a No. already used moves the others down.
 */
const props = defineProps<{ modelValue: boolean; member?: CommuneMember | null; year: number; place: PlacePick }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const form = reactive({ election_year: '', staff_id: '', role: 'full' as MemberRole, position: '', remark: '' })
const place = ref<PlacePick>({ province_id: '', district_id: '', commune_id: '', village_id: '' })
const staff = ref<MemberStaffOption[]>([])
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const roleOptions = computed(() => (['full', 'reserve'] as const).map((value) => ({ value, label: t(`admin.communeMembers.roles.${value}`) })))
const staffOptions = computed(() =>
  staff.value.map((s) => {
    const onOther = s.taken && s.taken.member_id !== props.member?.id
    const where = onOther ? ` — ${t('admin.communeMembers.onList', { commune: s.taken!.commune?.name_latin ?? '', role: t(`admin.communeMembers.roles.${s.taken!.role}`) })}` : ''
    return { value: String(s.id), label: `${s.name}${s.employee_code ? ` · ${s.employee_code}` : ''}${where}` }
  }),
)
const picked = computed(() => staff.value.find((s) => String(s.id) === form.staff_id) ?? null)
const takenElsewhere = computed(() => (picked.value?.taken && picked.value.taken.member_id !== props.member?.id ? picked.value.taken : null))

async function loadStaff() {
  staff.value = await communeMembersService.staffOptions(Number(form.election_year) || props.year).catch(() => [])
}

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    const m = props.member
    Object.assign(form, {
      election_year: String(m?.election_year ?? props.year),
      staff_id: m?.staff ? String(m.staff.id) : '',
      role: m?.role ?? 'full',
      position: m ? String(m.position) : '',
      remark: m?.remark ?? '',
    })
    place.value = m
      ? { province_id: String(m.province?.id ?? ''), district_id: String(m.district?.id ?? ''), commune_id: String(m.commune?.id ?? ''), village_id: '' }
      : { ...props.place, village_id: '' }
    errors.value = {}
    saveError.value = null
    void loadStaff()
  },
  { immediate: true },
)

// Which list each staff member is on depends on the year.
let yearTimer: ReturnType<typeof setTimeout> | undefined
watch(
  () => form.election_year,
  (year, before) => {
    if (!props.modelValue || !before || year.length !== 4) return
    clearTimeout(yearTimer)
    yearTimer = setTimeout(() => void loadStaff(), 300)
  },
)

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    election_year: Number(form.election_year),
    commune_id: Number(place.value.commune_id),
    staff_id: Number(form.staff_id),
    role: form.role,
    position: form.position === '' ? null : Number(form.position),
    remark: form.remark.trim() || null,
  }
  try {
    if (props.member) await communeMembersService.update(props.member.id, input)
    else await communeMembersService.create(input)
    emit('saved')
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.communeMembers.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="member ? t('admin.communeMembers.edit') : t('admin.communeMembers.add')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <BaseInput v-model="form.election_year" type="number" required :label="t('admin.communeMembers.year')" :error="errors.election_year?.[0]" />
      <SearchableSelect v-model="form.staff_id" :options="staffOptions" required :label="t('admin.communeMembers.staff')" :error="errors.staff_id?.[0]" />
      <p class="-mt-2 text-xs text-neutral-500">{{ t('admin.communeMembers.staffHint') }}</p>
      <BaseAlert v-if="takenElsewhere" variant="warning">{{ t('admin.communeMembers.takenWarning', { commune: takenElsewhere.commune?.name_latin ?? '' }) }}</BaseAlert>
      <div class="grid gap-4 sm:grid-cols-3">
        <PlaceSelects v-model="place" :depth="3" required :errors="{ commune_id: errors.commune_id?.[0] }" />
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseSelect v-model="form.role" :options="roleOptions" required :label="t('admin.communeMembers.type')" :error="errors.role?.[0]" />
        <BaseInput v-model="form.position" type="number" min="1" :label="t('admin.communeMembers.no')" :placeholder="t('admin.communeMembers.noHint')" :error="errors.position?.[0]" />
      </div>
      <BaseInput v-model="form.remark" :label="t('admin.communeMembers.remark')" :error="errors.remark?.[0]" />
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="!form.staff_id || !place.commune_id || !form.election_year || !!takenElsewhere" @click="save">{{ t('admin.communeMembers.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
