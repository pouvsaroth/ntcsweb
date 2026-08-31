<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { geographyService, type GeographyOption } from '@/services/geography'
import { positionsService, type Position } from '@/services/positions'
import { staffService, type StaffInput } from '@/services/staff'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const staffId = computed(() => (route.params.id ? Number(route.params.id) : null))
const isEditing = computed(() => staffId.value !== null)

// "Current Info" is the only tab with real form state — the other six are
// placeholders (see the module docblock at the top of the task spec): a
// coming-soon message instead of six half-built features. Purely
// client-side; switching tabs never touches the router or loses form data.
type TabKey = 'current-info' | 'emergency-contacts' | 'education' | 'family-members' | 'language' | 'previous-work' | 'documents'

const tabs: { key: TabKey; labelKey: string }[] = [
  { key: 'current-info', labelKey: 'admin.staff.tabCurrentInfo' },
  { key: 'emergency-contacts', labelKey: 'admin.staff.tabEmergencyContacts' },
  { key: 'education', labelKey: 'admin.staff.tabEducation' },
  { key: 'family-members', labelKey: 'admin.staff.tabFamilyMembers' },
  { key: 'language', labelKey: 'admin.staff.tabLanguage' },
  { key: 'previous-work', labelKey: 'admin.staff.tabPreviousWork' },
  { key: 'documents', labelKey: 'admin.staff.tabDocuments' },
]

const activeTab = ref<TabKey>('current-info')

const form = reactive<Omit<StaffInput, 'photo' | 'national_id_photo'>>({
  employee_code: '',
  first_name: '',
  last_name: '',
  other_name: '',
  gender: '',
  date_of_birth: '',
  birth_place: '',
  national_id: '',
  phone: '',
  email: '',
  house_no: '',
  street_no: '',
  village_code: '',
  facebook: '',
  telegram: '',
  other_contact: '',
  position_id: null,
  hire_date: '',
  status: 'active',
})

const photoFile = ref<File | null>(null)
const photoPreview = ref<string | null>(null)
const nationalIdPhotoFile = ref<File | null>(null)
const nationalIdPhotoPreview = ref<string | null>(null)

const positions = ref<Position[]>([])
const loading = ref(isEditing.value)
const loadError = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

/** Set only right after a successful create — see submit(). Shown once, never re-fetchable afterward. */
const temporaryPassword = ref<string | null>(null)

const genderOptions = computed(() => [
  { value: 'male', label: t('admin.staff.genderMale') },
  { value: 'female', label: t('admin.staff.genderFemale') },
  { value: 'other', label: t('admin.staff.genderOther') },
])

const statusOptions = computed(() => [
  { value: 'active', label: t('admin.staff.statusActive') },
  { value: 'inactive', label: t('admin.staff.statusInactive') },
])

const positionOptions = computed(() => positions.value.map((position) => ({ value: String(position.id), label: position.name })))

// Cambodia's official Province > District > Commune > Village hierarchy —
// selecting a village is what actually sets form.village_code. Copied
// verbatim from StudentForm.vue; see that file for the full rationale.
const provinces = ref<GeographyOption[]>([])
const districts = ref<GeographyOption[]>([])
const communes = ref<GeographyOption[]>([])
const villages = ref<GeographyOption[]>([])

const selectedProvinceId = ref('')
const selectedDistrictId = ref('')
const selectedCommuneId = ref('')
const selectedVillageId = ref('')

function toOptions(rows: GeographyOption[]) {
  return rows.map((row) => ({ value: String(row.id), label: `${row.name_km} — ${row.name_latin}` }))
}

const provinceOptions = computed(() => toOptions(provinces.value))
const districtOptions = computed(() => toOptions(districts.value))
const communeOptions = computed(() => toOptions(communes.value))
const villageOptions = computed(() => toOptions(villages.value))

async function onProvinceChange(value: string) {
  selectedProvinceId.value = value
  selectedDistrictId.value = ''
  selectedCommuneId.value = ''
  selectedVillageId.value = ''
  districts.value = []
  communes.value = []
  villages.value = []
  form.village_code = ''

  if (value) districts.value = await geographyService.districts(Number(value))
}

async function onDistrictChange(value: string) {
  selectedDistrictId.value = value
  selectedCommuneId.value = ''
  selectedVillageId.value = ''
  communes.value = []
  villages.value = []
  form.village_code = ''

  if (value) communes.value = await geographyService.communes(Number(value))
}

async function onCommuneChange(value: string) {
  selectedCommuneId.value = value
  selectedVillageId.value = ''
  villages.value = []
  form.village_code = ''

  if (value) villages.value = await geographyService.villages(Number(value))
}

function onVillageChange(value: string) {
  selectedVillageId.value = value
  form.village_code = villages.value.find((v) => String(v.id) === value)?.code ?? ''
}

/** Pre-selects all four dropdowns for a staff member that already has a village_code. */
async function selectAddressFromVillageCode(code: string) {
  const ancestry = await geographyService.lookup(code)

  selectedProvinceId.value = String(ancestry.province.id)
  districts.value = await geographyService.districts(ancestry.province.id)

  selectedDistrictId.value = String(ancestry.district.id)
  communes.value = await geographyService.communes(ancestry.district.id)

  selectedCommuneId.value = String(ancestry.commune.id)
  villages.value = await geographyService.villages(ancestry.commune.id)

  selectedVillageId.value = String(ancestry.village.id)
}

function onPhotoChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  photoFile.value = file
  photoPreview.value = URL.createObjectURL(file)
}

function onNationalIdPhotoChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  nationalIdPhotoFile.value = file
  nationalIdPhotoPreview.value = URL.createObjectURL(file)
}

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const [allPositions] = await Promise.all([positionsService.listAll(), geographyService.provinces().then((rows) => (provinces.value = rows))])
    positions.value = allPositions

    if (!staffId.value) return

    const staff = await staffService.get(staffId.value)
    form.employee_code = staff.employee_code
    form.first_name = staff.first_name
    form.last_name = staff.last_name
    form.other_name = staff.other_name ?? ''
    form.gender = staff.gender ?? ''
    form.date_of_birth = staff.date_of_birth ?? ''
    form.birth_place = staff.birth_place ?? ''
    form.national_id = staff.national_id ?? ''
    form.phone = staff.phone
    form.email = staff.email ?? ''
    form.house_no = staff.house_no ?? ''
    form.street_no = staff.street_no ?? ''
    form.village_code = staff.village_code ?? ''
    form.facebook = staff.facebook ?? ''
    form.telegram = staff.telegram ?? ''
    form.other_contact = staff.other_contact ?? ''
    form.position_id = staff.position?.id ?? null
    form.hire_date = staff.hire_date ?? ''
    form.status = staff.status
    photoPreview.value = staff.photo_url
    nationalIdPhotoPreview.value = staff.national_id_photo_url

    if (staff.village_code) await selectAddressFromVillageCode(staff.village_code)
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.staff.loadFailed')
  } finally {
    loading.value = false
  }
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  const payload: StaffInput = {
    ...form,
    photo: photoFile.value ?? undefined,
    national_id_photo: nationalIdPhotoFile.value ?? undefined,
  }

  try {
    if (isEditing.value) {
      await staffService.update(staffId.value!, payload)
      await router.push('/admin/staff')
    } else {
      const { temporaryPassword: password } = await staffService.create(payload)
      // The one-time password takes over this page instead of navigating
      // away — there is no other channel (no SMS, email is optional) to
      // relay it, same as the old StaffFormModal's behavior.
      temporaryPassword.value = password
    }
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.staff.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-4xl">
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">
        {{ isEditing ? t('admin.staff.editTitle') : t('admin.staff.createTitle') }}
      </h1>
    </div>

    <div v-if="temporaryPassword" class="max-w-lg space-y-4">
      <BaseAlert variant="success">{{ t('admin.staff.temporaryPasswordMessage') }}</BaseAlert>
      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.staff.temporaryPasswordLabel') }}</label>
        <code class="block rounded-lg border border-neutral-300 bg-neutral-50 px-3 py-2 text-sm font-mono text-neutral-900">
          {{ temporaryPassword }}
        </code>
      </div>
      <BaseButton @click="router.push('/admin/staff')">{{ t('admin.staff.close') }}</BaseButton>
    </div>

    <template v-else>
      <!-- Tab bar: only "Current Info" holds real state, so switching tabs
           is pure client-side UI — no route change, no data loss. -->
      <div class="mb-6 border-b border-neutral-200">
        <nav class="-mb-px flex flex-wrap gap-x-6 gap-y-1">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium"
            :class="
              activeTab === tab.key
                ? 'border-primary-600 text-primary-700'
                : 'border-transparent text-neutral-500 hover:border-neutral-300 hover:text-neutral-700'
            "
            @click="activeTab = tab.key"
          >
            {{ t(tab.labelKey) }}
          </button>
        </nav>
      </div>

      <div v-if="activeTab !== 'current-info'">
        <EmptyState :title="t('admin.comingSoon.notBuiltYet', { title: t(tabs.find((tab) => tab.key === activeTab)!.labelKey) })" :message="t('admin.comingSoon.message')" />
      </div>

      <template v-else>
        <BaseSpinner v-if="loading" class="mx-auto" />
        <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

        <form v-else class="space-y-10" @submit.prevent="submit">
          <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

          <!-- Basic / employment information -->
          <section>
            <h2 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.staff.basicSection') }}</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
              <BaseInput v-model="form.employee_code" required :label="t('admin.staff.employeeCode')" :error="errors.employee_code?.[0]" />
              <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.staff.status')" />

              <BaseInput v-model="form.first_name" required :label="t('admin.staff.firstName')" :error="errors.first_name?.[0]" />
              <BaseInput v-model="form.last_name" required :label="t('admin.staff.lastName')" :error="errors.last_name?.[0]" />
              <BaseInput v-model="form.other_name" :label="t('admin.staff.otherName')" :hint="t('admin.staff.otherNameHint')" :error="errors.other_name?.[0]" />

              <BaseSelect
                :model-value="form.position_id ? String(form.position_id) : ''"
                required
                :options="positionOptions"
                :placeholder="t('admin.staff.selectPosition')"
                :label="t('admin.staff.position')"
                :error="errors.position_id?.[0]"
                @update:model-value="(value: string) => (form.position_id = value ? Number(value) : null)"
              />
              <BaseInput v-model="form.hire_date" type="date" :label="t('admin.staff.hireDate')" :error="errors.hire_date?.[0]" />
            </div>
          </section>

          <!-- Personal information -->
          <section>
            <h2 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.staff.personalSection') }}</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
              <div>
                <p class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.staff.gender') }}</p>
                <div class="flex h-[42px] items-center gap-6">
                  <label v-for="option in genderOptions" :key="option.value" class="flex items-center gap-2 text-sm text-neutral-700">
                    <input
                      v-model="form.gender"
                      type="radio"
                      name="gender"
                      :value="option.value"
                      class="h-4 w-4 border-neutral-300 text-primary-600 focus:ring-primary-500"
                    />
                    {{ option.label }}
                  </label>
                </div>
              </div>
              <BaseInput v-model="form.date_of_birth" type="date" :label="t('admin.staff.dateOfBirth')" :error="errors.date_of_birth?.[0]" />

              <BaseInput v-model="form.birth_place" :label="t('admin.staff.birthPlace')" :error="errors.birth_place?.[0]" />
              <BaseInput v-model="form.national_id" :label="t('admin.staff.nationalId')" :error="errors.national_id?.[0]" />
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
              <div>
                <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.staff.photo') }}</label>
                <div v-if="photoPreview" class="mb-3 h-24 w-24 overflow-hidden rounded-full border border-neutral-200 bg-neutral-100">
                  <img :src="photoPreview" alt="" class="h-full w-full object-cover" />
                </div>
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp,image/gif"
                  class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
                  @change="onPhotoChange"
                />
                <p v-if="errors.photo?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.photo[0] }}</p>
              </div>

              <div>
                <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.staff.nationalIdPhoto') }}</label>
                <div v-if="nationalIdPhotoPreview" class="mb-3 h-24 w-36 overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100">
                  <img :src="nationalIdPhotoPreview" alt="" class="h-full w-full object-cover" />
                </div>
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp,image/gif"
                  class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
                  @change="onNationalIdPhotoChange"
                />
                <p v-if="errors.national_id_photo?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.national_id_photo[0] }}</p>
              </div>
            </div>
          </section>

          <!-- Contact information -->
          <section>
            <h2 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.staff.contactSection') }}</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
              <BaseInput v-model="form.phone" required :label="t('admin.staff.phone')" :error="errors.phone?.[0]" />
              <BaseInput v-model="form.email" type="email" :label="t('admin.staff.email')" :error="errors.email?.[0]" />
              <BaseInput v-model="form.facebook" :label="t('admin.staff.facebook')" :error="errors.facebook?.[0]" />
              <BaseInput v-model="form.telegram" :label="t('admin.staff.telegram')" :error="errors.telegram?.[0]" />
              <BaseInput v-model="form.other_contact" :label="t('admin.staff.otherContact')" :error="errors.other_contact?.[0]" />
            </div>
          </section>

          <!-- Address -->
          <section>
            <h2 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.staff.addressSection') }}</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
              <BaseInput v-model="form.house_no" :label="t('admin.staff.houseNo')" :error="errors.house_no?.[0]" />
              <BaseInput v-model="form.street_no" :label="t('admin.staff.streetNo')" :error="errors.street_no?.[0]" />
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <BaseSelect
                :model-value="selectedProvinceId"
                :options="provinceOptions"
                :placeholder="t('admin.staff.selectProvince')"
                :label="t('admin.staff.province')"
                @update:model-value="onProvinceChange"
              />
              <BaseSelect
                :model-value="selectedDistrictId"
                :options="districtOptions"
                :disabled="!selectedProvinceId"
                :placeholder="t('admin.staff.selectDistrict')"
                :label="t('admin.staff.district')"
                @update:model-value="onDistrictChange"
              />
              <BaseSelect
                :model-value="selectedCommuneId"
                :options="communeOptions"
                :disabled="!selectedDistrictId"
                :placeholder="t('admin.staff.selectCommune')"
                :label="t('admin.staff.commune')"
                @update:model-value="onCommuneChange"
              />
              <BaseSelect
                :model-value="selectedVillageId"
                :options="villageOptions"
                :disabled="!selectedCommuneId"
                :placeholder="t('admin.staff.selectVillage')"
                :label="t('admin.staff.village')"
                :error="errors.village_code?.[0]"
                @update:model-value="onVillageChange"
              />
            </div>
          </section>

          <div class="flex gap-3">
            <BaseButton type="submit" :loading="submitting">{{ t('common.save') }}</BaseButton>
            <BaseButton type="button" variant="outline" @click="router.push('/admin/staff')">{{ t('common.cancel') }}</BaseButton>
          </div>
        </form>
      </template>
    </template>
  </div>
</template>
