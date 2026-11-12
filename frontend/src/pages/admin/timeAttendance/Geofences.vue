<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import GeofenceMap from '@/components/admin/GeofenceMap.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseMultiSelect from '@/components/ui/BaseMultiSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { currentLocation } from '@/services/staffAttendance'
import { staffService, type Staff } from '@/services/staff'
import { geofencesService, parseLatLng, type Geofence, type GeofenceRule, type LatLng } from '@/services/timeAttendance'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Attendance & Time > Geofences — the places staff may check in / out
 * from the app: circles (pin + radius) or areas (polygon corners), drawn
 * on a map. While any place is on, check-in outside all of them is refused.
 * Below them, staff with their own rule: anywhere, or only some places.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canCreate = computed(() => auth.can('geofences.create'))
const canUpdate = computed(() => auth.can('geofences.update'))
const canDelete = computed(() => auth.can('geofences.delete'))

const zones = ref<Geofence[]>([])
const rules = ref<GeofenceRule[]>([])
const staff = ref<Staff[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const actionError = ref<string | null>(null)
const highlightId = ref<number | null>(null)
const activeCount = computed(() => zones.value.filter((z) => z.is_active).length)

async function load() {
  loading.value = true
  error.value = null
  try {
    ;[zones.value, rules.value] = await Promise.all([geofencesService.list(), geofencesService.rules()])
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.geofences.loadFailed')
  } finally {
    loading.value = false
  }
}

function sizeText(zone: Geofence): string {
  return zone.shape === 'polygon'
    ? t('admin.timeAttendance.geofences.cornersN', { count: zone.points?.length ?? 0 })
    : t('admin.timeAttendance.geofences.radiusN', { metres: zone.radius_m ?? 0 })
}

function zoneNames(ids: number[]): string {
  return ids.map((id) => zones.value.find((z) => z.id === id)?.name).filter(Boolean).join(', ') || '—'
}

// --- Place form ------------------------------------------------------------------

const formOpen = ref(false)
const editing = ref<Geofence | null>(null)
const form = reactive({ name: '', shape: 'circle' as 'circle' | 'polygon', center: null as LatLng | null, radius: 100, points: [] as LatLng[], is_active: true, note: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)
const editMap = ref<InstanceType<typeof GeofenceMap> | null>(null)
const me = ref<{ lat: number; lng: number; accuracy: number | null } | null>(null)
const locating = ref(false)
const locateError = ref<string | null>(null)
const pasted = ref('')
const pasteError = ref<string | null>(null)

function open(zone: Geofence | null) {
  editing.value = zone
  form.name = zone?.name ?? ''
  form.shape = zone?.shape ?? 'circle'
  form.center = zone && zone.shape === 'circle' ? [zone.latitude, zone.longitude] : null
  form.radius = zone?.radius_m ?? 100
  form.points = zone?.points ? zone.points.map((p) => [p[0], p[1]] as LatLng) : []
  form.is_active = zone?.is_active ?? true
  form.note = zone?.note ?? ''
  errors.value = {}
  saveError.value = null
  locateError.value = null
  pasted.value = ''
  pasteError.value = null
  formOpen.value = true
}

/** Puts the pin (circle) or the map (area) where the person is standing. */
async function useMyLocation() {
  locating.value = true
  locateError.value = null
  const location = await currentLocation()
  locating.value = false
  if (!location) {
    locateError.value = t('admin.timeAttendance.geofences.locateFailed')
    return
  }
  me.value = { lat: location.latitude, lng: location.longitude, accuracy: location.accuracy }
  goTo([location.latitude, location.longitude])
}

function goTo(point: LatLng) {
  if (form.shape === 'circle') form.center = point
  editMap.value?.flyTo(point)
}

function applyPasted() {
  const point = parseLatLng(pasted.value)
  pasteError.value = point ? null : t('admin.timeAttendance.geofences.pasteFailed')
  if (point) goTo(point)
}

function setShape(shape: 'circle' | 'polygon') {
  form.shape = shape
  errors.value = {}
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input =
    form.shape === 'circle'
      ? { name: form.name, shape: form.shape, latitude: form.center?.[0] ?? null, longitude: form.center?.[1] ?? null, radius_m: form.radius || null, is_active: form.is_active, note: form.note.trim() || null }
      : { name: form.name, shape: form.shape, points: form.points, is_active: form.is_active, note: form.note.trim() || null }
  try {
    editing.value ? await geofencesService.update(editing.value.id, input) : await geofencesService.create(input)
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(zone: Geofence) {
  if (!(await confirmDialog.confirm({ message: t('admin.timeAttendance.geofences.deleteConfirm', { name: zone.name }), danger: true }))) return
  actionError.value = null
  try {
    await geofencesService.remove(zone.id)
    await load()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

/** Turns a place on or off without opening the form. */
async function toggle(zone: Geofence) {
  actionError.value = null
  try {
    await geofencesService.update(zone.id, { name: zone.name, shape: zone.shape, latitude: zone.latitude, longitude: zone.longitude, radius_m: zone.radius_m, points: zone.points, is_active: !zone.is_active, note: zone.note })
    await load()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.saveFailed')
  }
}

// --- Staff rules -------------------------------------------------------------------

const ruleOpen = ref(false)
const ruleEditing = ref<GeofenceRule | null>(null)
const ruleForm = reactive({ staff_id: '', mode: 'anywhere' as 'anywhere' | 'zones', geofence_ids: [] as string[] })
const ruleErrors = ref<Record<string, string[]>>({})
const ruleSaveError = ref<string | null>(null)
const ruleSaving = ref(false)

const staffOptions = computed(() => staff.value.map((s) => ({ value: String(s.id), label: s.full_name, hint: s.employee_code })))
const zoneOptions = computed(() => zones.value.map((z) => ({ value: String(z.id), label: z.is_active ? z.name : `${z.name} (${t('admin.timeAttendance.geofences.off')})` })))

async function openRule(rule: GeofenceRule | null) {
  ruleEditing.value = rule
  ruleForm.staff_id = rule ? String(rule.staff_id) : ''
  ruleForm.mode = rule?.mode ?? 'anywhere'
  ruleForm.geofence_ids = rule ? rule.geofence_ids.map(String) : []
  ruleErrors.value = {}
  ruleSaveError.value = null
  ruleOpen.value = true
  if (staff.value.length === 0) staff.value = await staffService.listAll().catch(() => [])
}

async function saveRule() {
  ruleSaving.value = true
  ruleErrors.value = {}
  ruleSaveError.value = null
  try {
    await geofencesService.saveRule({
      staff_id: Number(ruleForm.staff_id),
      mode: ruleForm.mode,
      geofence_ids: ruleForm.mode === 'zones' ? ruleForm.geofence_ids.map(Number) : null,
    })
    ruleOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) ruleErrors.value = e.errors
    else ruleSaveError.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.saveFailed')
  } finally {
    ruleSaving.value = false
  }
}

async function removeRule(rule: GeofenceRule) {
  if (!(await confirmDialog.confirm({ message: t('admin.timeAttendance.geofences.ruleDeleteConfirm', { name: rule.staff_name ?? '' }), danger: true }))) return
  actionError.value = null
  try {
    await geofencesService.removeRule(rule.id)
    await load()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

onMounted(() => void load())
</script>

<template>
  <div class="space-y-6">
    <BaseAlert v-if="error || actionError" variant="danger">{{ error || actionError }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-else>
      <!-- What it does, in one line -->
      <div class="flex flex-wrap items-start gap-3">
        <div class="min-w-0 flex-1">
          <p class="text-sm font-medium" :class="activeCount > 0 ? 'text-success-700' : 'text-neutral-600'">
            {{ activeCount > 0 ? t('admin.timeAttendance.geofences.onSummary', { count: activeCount }, activeCount) : t('admin.timeAttendance.geofences.offSummary') }}
          </p>
          <p class="text-xs text-neutral-500">{{ t('admin.timeAttendance.geofences.help') }}</p>
        </div>
        <BaseButton v-if="canCreate" @click="open(null)">{{ t('admin.timeAttendance.geofences.add') }}</BaseButton>
      </div>

      <div v-if="zones.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 px-4 py-10 text-center">
        <p class="text-sm font-medium text-neutral-700">{{ t('admin.timeAttendance.geofences.empty') }}</p>
        <p class="mt-1 text-xs text-neutral-500">{{ t('admin.timeAttendance.geofences.emptyHint') }}</p>
      </div>

      <div v-else class="grid gap-4 lg:grid-cols-5">
        <GeofenceMap class="lg:col-span-3" :zones="zones" :highlight-id="highlightId" height="420px" />

        <!-- The places: cards at every size (a short list beside the map) -->
        <div class="space-y-2 lg:col-span-2">
          <div
            v-for="zone in zones"
            :key="zone.id"
            class="cursor-pointer rounded-[--radius-card] border bg-white p-3 shadow-[--shadow-card] transition"
            :class="highlightId === zone.id ? 'border-primary-400 ring-2 ring-primary-100' : 'border-neutral-200 hover:border-neutral-300'"
            @click="highlightId = highlightId === zone.id ? null : zone.id"
          >
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-neutral-800">{{ zone.name }}</p>
                <p class="text-xs text-neutral-500">
                  {{ zone.shape === 'polygon' ? t('admin.timeAttendance.geofences.shapePolygon') : t('admin.timeAttendance.geofences.shapeCircle') }} · {{ sizeText(zone) }}
                </p>
                <p v-if="zone.note" class="mt-0.5 truncate text-xs text-neutral-500">{{ zone.note }}</p>
              </div>
              <BaseBadge :variant="zone.is_active ? 'success' : 'neutral'">{{ zone.is_active ? t('admin.timeAttendance.geofences.on') : t('admin.timeAttendance.geofences.off') }}</BaseBadge>
            </div>
            <div v-if="canUpdate || canDelete" class="mt-2 flex items-center justify-end gap-3" @click.stop>
              <button v-if="canUpdate" type="button" class="text-sm font-medium text-neutral-600 hover:text-neutral-800" @click="toggle(zone)">
                {{ zone.is_active ? t('admin.timeAttendance.geofences.turnOff') : t('admin.timeAttendance.geofences.turnOn') }}
              </button>
              <EditIconButton v-if="canUpdate" @click="open(zone)" />
              <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(zone)">{{ t('admin.organization.delete') }}</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Staff with their own rule -->
      <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <div class="mb-3 flex flex-wrap items-start gap-2">
          <div class="min-w-0 flex-1">
            <h3 class="text-sm font-semibold text-neutral-800">{{ t('admin.timeAttendance.geofences.rulesTitle') }}</h3>
            <p class="text-xs text-neutral-500">{{ t('admin.timeAttendance.geofences.rulesHelp') }}</p>
          </div>
          <BaseButton v-if="canCreate" size="sm" variant="outline" @click="openRule(null)">{{ t('admin.timeAttendance.geofences.addRule') }}</BaseButton>
        </div>
        <p v-if="rules.length === 0" class="py-4 text-center text-sm text-neutral-500">{{ t('admin.timeAttendance.geofences.rulesEmpty') }}</p>
        <ul v-else class="divide-y divide-neutral-100">
          <li v-for="rule in rules" :key="rule.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium text-neutral-800">{{ rule.staff_name }} <span class="text-xs font-normal text-neutral-500">{{ rule.employee_code }}</span></p>
              <p class="text-xs text-neutral-500">
                <BaseBadge :variant="rule.mode === 'anywhere' ? 'primary' : 'warning'">{{ rule.mode === 'anywhere' ? t('admin.timeAttendance.geofences.modeAnywhere') : t('admin.timeAttendance.geofences.modeZones') }}</BaseBadge>
                <span v-if="rule.mode === 'zones'" class="ml-1">{{ zoneNames(rule.geofence_ids) }}</span>
              </p>
            </div>
            <div class="flex items-center gap-3">
              <EditIconButton v-if="canUpdate" @click="openRule(rule)" />
              <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removeRule(rule)">{{ t('admin.organization.delete') }}</button>
            </div>
          </li>
        </ul>
      </section>
    </template>

    <!-- Add / edit a place -->
    <BaseModal v-model="formOpen" size="lg" :title="editing ? t('admin.timeAttendance.geofences.edit') : t('admin.timeAttendance.geofences.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <BaseInput v-model="form.name" required :label="t('admin.timeAttendance.geofences.name')" :placeholder="t('admin.timeAttendance.geofences.namePlaceholder')" :error="errors.name?.[0]" />

        <div>
          <p class="mb-1 text-sm font-medium text-neutral-700">{{ t('admin.timeAttendance.geofences.shape') }}</p>
          <div class="grid grid-cols-2 gap-2">
            <button
              v-for="option in (['circle', 'polygon'] as const)"
              :key="option"
              type="button"
              class="rounded-lg border px-3 py-2 text-left transition"
              :class="form.shape === option ? 'border-primary-500 bg-primary-50 ring-1 ring-primary-500' : 'border-neutral-300 hover:border-neutral-400'"
              @click="setShape(option)"
            >
              <p class="text-sm font-semibold text-neutral-800">{{ option === 'circle' ? t('admin.timeAttendance.geofences.shapeCircle') : t('admin.timeAttendance.geofences.shapePolygon') }}</p>
              <p class="text-xs text-neutral-500">{{ option === 'circle' ? t('admin.timeAttendance.geofences.shapeCircleHint') : t('admin.timeAttendance.geofences.shapePolygonHint') }}</p>
            </button>
          </div>
        </div>

        <div class="flex flex-wrap items-end gap-2">
          <BaseButton type="button" variant="outline" size="sm" :loading="locating" @click="useMyLocation">{{ t('admin.timeAttendance.geofences.useMyLocation') }}</BaseButton>
          <div class="flex min-w-0 flex-1 gap-2">
            <input
              v-model="pasted"
              type="text"
              class="min-w-0 flex-1 rounded-lg border-neutral-300 text-sm focus:border-primary-500 focus:ring-primary-500"
              :placeholder="t('admin.timeAttendance.geofences.pastePlaceholder')"
              @keydown.enter.prevent="applyPasted"
            />
            <BaseButton type="button" variant="outline" size="sm" @click="applyPasted">{{ t('admin.timeAttendance.geofences.go') }}</BaseButton>
          </div>
        </div>
        <p v-if="locateError || pasteError" class="text-xs text-danger-600">{{ locateError || pasteError }}</p>

        <div>
          <p class="mb-1 text-xs text-neutral-500">{{ form.shape === 'circle' ? t('admin.timeAttendance.geofences.circleTip') : t('admin.timeAttendance.geofences.polygonTip') }}</p>
          <GeofenceMap
            v-if="formOpen"
            ref="editMap"
            editable
            :zones="zones.filter((z) => z.id !== editing?.id)"
            :highlight-id="-1"
            :shape="form.shape"
            :center="form.center"
            :radius="form.radius"
            :points="form.points"
            :me="me"
            height="340px"
            @update:center="form.center = $event"
            @update:points="form.points = $event"
          />
          <p v-if="errors.latitude?.[0] || errors.points?.[0]" class="mt-1 text-xs text-danger-600">{{ errors.latitude?.[0] || errors.points?.[0] }}</p>
        </div>

        <div v-if="form.shape === 'circle'" class="grid items-end gap-3 sm:grid-cols-[1fr_8rem]">
          <div>
            <label class="mb-1 block text-sm font-medium text-neutral-700">{{ t('admin.timeAttendance.geofences.radius') }}</label>
            <input v-model.number="form.radius" type="range" min="20" max="1000" step="10" class="w-full accent-primary-600" />
          </div>
          <BaseInput :model-value="String(form.radius)" type="number" @update:model-value="form.radius = Number($event) || 0" min="10" max="20000" :label="t('admin.timeAttendance.geofences.metres')" :error="errors.radius_m?.[0]" />
        </div>
        <div v-else class="flex flex-wrap items-center gap-2">
          <p class="flex-1 text-sm text-neutral-600">{{ t('admin.timeAttendance.geofences.cornersN', { count: form.points.length }) }}</p>
          <BaseButton type="button" variant="outline" size="sm" :disabled="form.points.length === 0" @click="form.points = form.points.slice(0, -1)">{{ t('admin.timeAttendance.geofences.undo') }}</BaseButton>
          <BaseButton type="button" variant="outline" size="sm" :disabled="form.points.length === 0" @click="form.points = []">{{ t('admin.timeAttendance.geofences.clear') }}</BaseButton>
        </div>

        <BaseInput v-model="form.note" :label="t('admin.timeAttendance.geofences.note')" />
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.timeAttendance.geofences.activeLabel') }}
        </label>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <!-- A staff member's own rule -->
    <BaseModal v-model="ruleOpen" :title="t('admin.timeAttendance.geofences.ruleTitle')">
      <form class="space-y-4" @submit.prevent="saveRule">
        <BaseAlert v-if="ruleSaveError" variant="danger">{{ ruleSaveError }}</BaseAlert>
        <SearchableSelect
          v-model="ruleForm.staff_id"
          required
          :disabled="ruleEditing !== null"
          :options="staffOptions"
          :label="t('admin.timeAttendance.entry.staff')"
          :placeholder="t('admin.timeAttendance.entry.selectStaff')"
          :error="ruleErrors.staff_id?.[0]"
        />
        <div class="space-y-2">
          <label v-for="mode in (['anywhere', 'zones'] as const)" :key="mode" class="flex cursor-pointer items-start gap-2 rounded-lg border p-3" :class="ruleForm.mode === mode ? 'border-primary-500 bg-primary-50' : 'border-neutral-200'">
            <input v-model="ruleForm.mode" type="radio" :value="mode" class="mt-0.5 border-neutral-300 text-primary-600 focus:ring-primary-500" />
            <span>
              <span class="block text-sm font-medium text-neutral-800">{{ mode === 'anywhere' ? t('admin.timeAttendance.geofences.modeAnywhere') : t('admin.timeAttendance.geofences.modeZones') }}</span>
              <span class="block text-xs text-neutral-500">{{ mode === 'anywhere' ? t('admin.timeAttendance.geofences.modeAnywhereHint') : t('admin.timeAttendance.geofences.modeZonesHint') }}</span>
            </span>
          </label>
        </div>
        <BaseMultiSelect
          v-if="ruleForm.mode === 'zones'"
          v-model="ruleForm.geofence_ids"
          :options="zoneOptions"
          :label="t('admin.timeAttendance.geofences.places')"
          :placeholder="t('admin.timeAttendance.geofences.pickPlaces')"
          :error="ruleErrors.geofence_ids?.[0]"
        />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="ruleOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="ruleSaving" @click="saveRule">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
