<script setup lang="ts">
import { useElementSize } from '@vueuse/core'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'

import StaffCardCanvas from '@/components/admin/StaffCardCanvas.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useCardKind } from '@/composables/useCardKind'
import { staffService, type Staff } from '@/services/staff'
import { staffCardDesignsService, type CardStudent, type StaffCardDesign } from '@/services/staffCardDesigns'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import {
  CARD_FONTS,
  CARD_PRESETS,
  isImageField,
  newField,
  fieldTypesFor,
  staffCardValues,
  studentCardValues,
  type CardAlign,
  type CardField,
  type CardFieldType,
  type CardShape,
} from '@/utils/staffCard'

/**
 * HRM > Staff cards > design: set the card size, upload a photo for the front
 * and the back, then drag fields onto the card (or tap a field to add it),
 * move / resize them and set font size, colour, bold and alignment.
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const designId = computed(() => (route.params.id ? Number(route.params.id) : null))
const { cardFor, isStudent, base, routePrefix, permission, fieldLabel, sample } = useCardKind()
const canSave = computed(() => (designId.value ? auth.can(permission('update')) : auth.can(permission('create'))))
const fieldTypes = computed(() => fieldTypesFor(cardFor.value))

type Side = 'front' | 'back'
const form = reactive({ name: '', width: 85.6, height: 54 })
const fields = reactive<Record<Side, CardField[]>>({ front: [], back: [] })
const side = ref<Side>('front')
const selectedId = ref<string | null>(null)

// Photos: the saved URL, a newly picked file (with a preview URL) or removed.
const saved = ref<StaffCardDesign | null>(null)
type PhotoKey = 'front_background' | 'back_background' | 'signature'
const files = reactive<Record<PhotoKey, File | null>>({ front_background: null, back_background: null, signature: null })
const previews = reactive<Record<PhotoKey, string | null>>({ front_background: null, back_background: null, signature: null })
const removed = reactive<Record<PhotoKey, boolean>>({ front_background: false, back_background: false, signature: false })
function photoUrl(key: PhotoKey): string | null {
  if (previews[key]) return previews[key]
  if (removed[key]) return null
  return saved.value?.[`${key}_url`] ?? null
}
function pickPhoto(key: PhotoKey, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  if (previews[key]) URL.revokeObjectURL(previews[key]!)
  files[key] = file
  previews[key] = URL.createObjectURL(file)
  removed[key] = false
  dirty.value = true
}
function removePhoto(key: PhotoKey) {
  if (previews[key]) URL.revokeObjectURL(previews[key]!)
  files[key] = null
  previews[key] = null
  removed[key] = true
  dirty.value = true
}
onBeforeUnmount(() => Object.values(previews).forEach((url) => url && URL.revokeObjectURL(url)))

const backgroundKey = computed<PhotoKey>(() => (side.value === 'front' ? 'front_background' : 'back_background'))

// ---- Size ------------------------------------------------------------------
const preset = computed(() => CARD_PRESETS.find((p) => p.width === Number(form.width) && p.height === Number(form.height))?.key ?? 'custom')
const presetOptions = computed(() => [...CARD_PRESETS.map((p) => ({ value: p.key, label: t(`admin.staffCards.sizes.${p.key}`) })), { value: 'custom', label: t('admin.staffCards.sizes.custom') }])
const customSize = ref(false)
function onPreset(key: string) {
  const found = CARD_PRESETS.find((p) => p.key === key)
  customSize.value = !found
  if (found) {
    form.width = found.width
    form.height = found.height
    dirty.value = true
  }
}
const cardW = computed(() => Math.min(Math.max(Number(form.width) || 0, 20), 300))
const cardH = computed(() => Math.min(Math.max(Number(form.height) || 0, 20), 300))

// ---- Canvas scale: fill the space, at most 8 px per mm --------------------
const stage = ref<HTMLElement | null>(null)
const { width: stageWidth } = useElementSize(stage)
const scale = computed(() => {
  const fit = (stageWidth.value - 24) / cardW.value
  const tall = 560 / cardH.value
  return Math.max(Math.min(fit, tall, 8), 1.5)
})

// ---- Preview data ----------------------------------------------------------
// Staff cards preview with a staff member, student cards with a student.
const staffList = ref<Staff[]>([])
const studentList = ref<CardStudent[]>([])
const previewId = ref('')
const previewStaff = computed(() => staffList.value.find((s) => String(s.id) === previewId.value) ?? null)
const previewStudent = computed(() => studentList.value.find((s) => String(s.id) === previewId.value) ?? null)
const staffOptions = computed(() =>
  isStudent.value
    ? [{ value: '', label: t('admin.staffCards.sampleStudent') }, ...studentList.value.map((s) => ({ value: String(s.id), label: `${s.student_code} · ${s.full_name}` }))]
    : [{ value: '', label: t('admin.staffCards.sampleStaff') }, ...staffList.value.map((s) => ({ value: String(s.id), label: `${s.employee_code} · ${s.full_name}` }))],
)
const nextYear = new Date()
nextYear.setFullYear(nextYear.getFullYear() + 1)
const values = computed(() => {
  const common = {
    logoUrl: saved.value?.school_logo_url ?? null,
    signatureUrl: photoUrl('signature'),
    expiry: formatDate(nextYear),
    genderLabel: (g: string) => (['male', 'female'].includes(g.toLowerCase()) ? t(`admin.staffCards.genders.${g.toLowerCase()}`) : g),
    sample: sample.value,
  }
  return isStudent.value ? studentCardValues({ ...common, student: previewStudent.value }) : staffCardValues({ ...common, staff: previewStaff.value })
})
const placeholder = (field: CardField) => (field.type === 'text' ? t('admin.staffCards.yourText') : fieldLabel(field.type))

// ---- Fields ------------------------------------------------------------------
const current = computed(() => fields[side.value])
const selected = computed(() => current.value.find((f) => f.id === selectedId.value) ?? null)

function addField(type: CardFieldType, x?: number, y?: number) {
  // Tapped (not dropped): step each new field down-right so they don't stack.
  const step = (current.value.length % 6) * 3
  const field = newField(type, cardW.value, cardH.value, x ?? cardW.value / 2 - 6 + step, y ?? cardH.value / 2 - 6 + step)
  if (type === 'text') field.text = t('admin.staffCards.yourText')
  fields[side.value].push(field)
  selectedId.value = field.id
  dirty.value = true
}
function onDrop(type: string, x: number, y: number) {
  if ((fieldTypes.value as string[]).includes(type)) addField(type as CardFieldType, x, y)
}
function onDragStart(event: DragEvent, type: CardFieldType) {
  event.dataTransfer?.setData('text/staff-card-field', type)
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'copy'
}
function onChange(next: CardField) {
  const list = fields[side.value]
  const i = list.findIndex((f) => f.id === next.id)
  if (i >= 0) list[i] = next
  dirty.value = true
}
function patch(values: Partial<CardField>) {
  if (!selected.value) return
  onChange({ ...selected.value, ...values })
}
const num = (value: string, min: number, max: number) => Math.min(Math.max(Math.round((Number(value) || 0) * 10) / 10, min), max)
function removeSelected() {
  fields[side.value] = current.value.filter((f) => f.id !== selectedId.value)
  selectedId.value = null
  dirty.value = true
}
function duplicateSelected() {
  if (!selected.value) return
  const copy = { ...selected.value, id: Math.random().toString(36).slice(2, 10), x: selected.value.x + 2, y: selected.value.y + 2 }
  fields[side.value].push(copy)
  selectedId.value = copy.id
  dirty.value = true
}
watch(side, () => (selectedId.value = null))

// Arrow keys nudge the selected field 0.5 mm (Shift: 2 mm); Delete removes it.
function onKey(event: KeyboardEvent) {
  const target = event.target as HTMLElement
  if (!selected.value || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) return
  const step = event.shiftKey ? 2 : 0.5
  const moves: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }
  if (moves[event.key]) {
    event.preventDefault()
    patch({ x: Math.round((selected.value.x + moves[event.key][0]) * 10) / 10, y: Math.round((selected.value.y + moves[event.key][1]) * 10) / 10 })
  } else if (event.key === 'Delete') {
    removeSelected()
  }
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

const fontOptions = computed(() => [
  { value: '', label: t('admin.staffCards.fontDefault') },
  ...CARD_FONTS.map((f) => ({ value: f.name, label: f.khmer ? `${f.name} · ${t('admin.staffCards.fontKhmer')}` : f.name })),
])

const alignOptions = computed(() => (['left', 'center', 'right'] as CardAlign[]).map((a) => ({ value: a, label: t(`admin.staffCards.align.${a}`) })))
const shapeOptions = computed(() => (['rect', 'rounded', 'circle'] as CardShape[]).map((s) => ({ value: s, label: t(`admin.staffCards.shapes.${s}`) })))

// ---- Load / save -------------------------------------------------------------
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const dirty = ref(false)

async function load() {
  if (!designId.value) return
  loading.value = true
  try {
    const design = await staffCardDesignsService.get(designId.value)
    saved.value = design
    form.name = design.name
    form.width = design.width_mm
    form.height = design.height_mm
    fields.front = design.front_fields
    fields.back = design.back_fields
    customSize.value = preset.value === 'custom'
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.staffCards.loadFailed')
  } finally {
    loading.value = false
  }
}

async function save() {
  error.value = null
  notice.value = null
  if (!form.name.trim()) {
    error.value = t('admin.staffCards.nameRequired')
    return
  }
  saving.value = true
  try {
    const input = {
      ...(designId.value ? {} : { card_for: cardFor.value }),
      name: form.name.trim(),
      width_mm: cardW.value,
      height_mm: cardH.value,
      front_fields: fields.front,
      back_fields: fields.back,
      front_background: files.front_background,
      back_background: files.back_background,
      signature: files.signature,
      remove_front_background: removed.front_background,
      remove_back_background: removed.back_background,
      remove_signature: removed.signature,
    }
    const design = designId.value ? await staffCardDesignsService.update(designId.value, input) : await staffCardDesignsService.create(input)
    saved.value = design
    for (const key of ['front_background', 'back_background', 'signature'] as PhotoKey[]) {
      if (previews[key]) URL.revokeObjectURL(previews[key]!)
      files[key] = null
      previews[key] = null
      removed[key] = false
    }
    dirty.value = false
    notice.value = t('admin.staffCards.saved')
    if (!designId.value) await router.replace({ name: `${routePrefix.value}.edit`, params: { id: design.id } })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.staffCards.saveFailed')
  } finally {
    saving.value = false
  }
}

onBeforeRouteLeave(async () => {
  if (!dirty.value) return true
  return confirmDialog.confirm(t('admin.staffCards.unsaved'))
})

onMounted(async () => {
  await load()
  // A design opened under the other kind of card: go to its own page.
  if (saved.value && saved.value.card_for !== cardFor.value) {
    dirty.value = false
    await router.replace(`${saved.value.card_for === 'student' ? '/admin/student-cards' : '/admin/staff-cards'}/${saved.value.id}/edit`)
    return
  }
  // Preview with a real person: students for student cards, staff (when this user can see staff) for staff cards.
  if (isStudent.value) studentList.value = await staffCardDesignsService.students({}).catch(() => [])
  else if (auth.can('staff.view')) staffList.value = await staffService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <RouterLink :to="base" class="text-sm font-medium text-primary-700 hover:underline">← {{ t('admin.staffCards.backToList') }}</RouterLink>
      <div class="flex items-center gap-3">
        <span v-if="dirty" class="text-xs text-warning-700">{{ t('admin.staffCards.unsavedShort') }}</span>
        <RouterLink v-if="designId && !dirty" :to="`${base}/${designId}/print`" class="text-sm font-medium text-primary-700 hover:underline">{{ t('admin.staffCards.printCards') }}</RouterLink>
        <BaseButton v-if="canSave" :loading="saving" @click="save">{{ t('admin.staffCards.save') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="success" class="mb-4">{{ notice }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-16"><BaseSpinner /></div>
    <template v-else>
      <!-- Name and size -->
      <div class="mb-4 grid gap-3 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_16rem_8rem_8rem]">
        <BaseInput v-model="form.name" :label="t('admin.staffCards.name')" :placeholder="t('admin.staffCards.namePlaceholder')" required @update:model-value="dirty = true" />
        <BaseSelect :model-value="customSize ? 'custom' : preset" :options="presetOptions" :label="t('admin.staffCards.size')" @update:model-value="onPreset" />
        <BaseInput
          :model-value="String(form.width)"
          type="number"
          :label="t('admin.staffCards.widthMm')"
          :disabled="!customSize && preset !== 'custom'"
          @update:model-value="((form.width = Number($event)), (dirty = true))"
        />
        <BaseInput
          :model-value="String(form.height)"
          type="number"
          :label="t('admin.staffCards.heightMm')"
          :disabled="!customSize && preset !== 'custom'"
          @update:model-value="((form.height = Number($event)), (dirty = true))"
        />
      </div>

      <div class="grid gap-4 lg:grid-cols-[13rem_minmax(0,1fr)_16rem]">
        <!-- Field list: drag onto the card, or tap to add -->
        <aside class="order-3 rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card] lg:order-1">
          <p class="text-sm font-semibold text-neutral-900">{{ t('admin.staffCards.fields') }}</p>
          <p class="mb-2 text-xs text-neutral-500">{{ t('admin.staffCards.fieldsHint') }}</p>
          <div class="grid grid-cols-2 gap-1.5 lg:grid-cols-1">
            <button
              v-for="type in fieldTypes"
              :key="type"
              type="button"
              draggable="true"
              class="flex cursor-grab items-center gap-2 rounded-md border border-neutral-200 px-2 py-1.5 text-left text-xs text-neutral-700 hover:border-primary-300 hover:bg-primary-50 active:cursor-grabbing"
              @dragstart="onDragStart($event, type)"
              @click="addField(type)"
            >
              <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded bg-neutral-100 text-[10px] font-semibold text-neutral-500">{{ isImageField(type) ? '▣' : 'T' }}</span>
              {{ fieldLabel(type) }}
            </button>
          </div>
        </aside>

        <!-- The card -->
        <section class="order-1 min-w-0 rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card] lg:order-2">
          <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div class="inline-flex rounded-lg border border-neutral-200 p-0.5">
              <button
                v-for="s in ['front', 'back'] as const"
                :key="s"
                type="button"
                class="rounded-md px-3 py-1 text-sm font-medium"
                :class="side === s ? 'bg-primary-600 text-white' : 'text-neutral-600 hover:bg-neutral-100'"
                @click="side = s"
              >
                {{ t(`admin.staffCards.${s}`) }} <span class="text-xs opacity-75">({{ fields[s].length }})</span>
              </button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <label class="cursor-pointer rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                {{ photoUrl(backgroundKey) ? t('admin.staffCards.changeBackground') : t('admin.staffCards.uploadBackground') }}
                <input type="file" accept="image/*" class="hidden" @change="pickPhoto(backgroundKey, $event)" />
              </label>
              <button v-if="photoUrl(backgroundKey)" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removePhoto(backgroundKey)">
                {{ t('admin.staffCards.removeBackground') }}
              </button>
            </div>
          </div>

          <div ref="stage" class="flex justify-center overflow-hidden rounded-lg bg-neutral-100 p-3">
            <StaffCardCanvas
              :width-mm="cardW"
              :height-mm="cardH"
              :background="photoUrl(backgroundKey)"
              :fields="current"
              :values="values"
              :scale="scale"
              :selected-id="selectedId"
              :placeholder="placeholder"
              editable
              @select="selectedId = $event"
              @change="onChange"
              @drop="onDrop"
            />
          </div>
          <p class="mt-2 text-xs text-neutral-500">{{ t('admin.staffCards.backgroundHint', { w: cardW, h: cardH, pw: Math.round((cardW / 25.4) * 300), ph: Math.round((cardH / 25.4) * 300) }) }}</p>

          <div class="mt-3 max-w-sm">
            <BaseSelect v-model="previewId" :options="staffOptions" :label="t('admin.staffCards.previewWith')" />
          </div>
        </section>

        <!-- The selected field -->
        <aside class="order-2 rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card] lg:order-3">
          <p v-if="!selected" class="text-sm text-neutral-500">{{ t('admin.staffCards.noSelection') }}</p>
          <div v-else class="space-y-3">
            <p class="text-sm font-semibold text-neutral-900">{{ fieldLabel(selected.type) }}</p>
            <BaseInput v-if="selected.type === 'text'" :model-value="selected.text ?? ''" :label="t('admin.staffCards.text')" @update:model-value="patch({ text: $event })" />
            <div class="grid grid-cols-2 gap-2">
              <BaseInput :model-value="String(selected.x)" type="number" :label="t('admin.staffCards.x')" @update:model-value="patch({ x: num($event, -300, 300) })" />
              <BaseInput :model-value="String(selected.y)" type="number" :label="t('admin.staffCards.y')" @update:model-value="patch({ y: num($event, -300, 300) })" />
              <BaseInput :model-value="String(selected.w)" type="number" :label="t('admin.staffCards.w')" @update:model-value="patch({ w: num($event, 1, 300) })" />
              <BaseInput :model-value="String(selected.h)" type="number" :label="t('admin.staffCards.h')" @update:model-value="patch({ h: num($event, 1, 300) })" />
            </div>
            <template v-if="!isImageField(selected.type)">
              <BaseSelect :model-value="selected.font ?? ''" :options="fontOptions" :label="t('admin.staffCards.font')" @update:model-value="patch({ font: $event || undefined })" />
              <div class="grid grid-cols-2 gap-2">
                <BaseInput :model-value="String(selected.font_size ?? 8)" type="number" :label="t('admin.staffCards.fontSize')" @update:model-value="patch({ font_size: num($event, 3, 72) })" />
                <label class="block text-sm">
                  <span class="mb-1 block font-medium text-neutral-700">{{ t('admin.staffCards.color') }}</span>
                  <input type="color" :value="selected.color ?? '#111111'" class="h-[38px] w-full cursor-pointer rounded-lg border border-neutral-300 bg-white p-1" @input="patch({ color: ($event.target as HTMLInputElement).value })" />
                </label>
              </div>
              <BaseSelect :model-value="selected.align ?? 'left'" :options="alignOptions" :label="t('admin.staffCards.alignLabel')" @update:model-value="patch({ align: $event as CardAlign })" />
              <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" :checked="!!selected.bold" class="rounded border-neutral-300" @change="patch({ bold: ($event.target as HTMLInputElement).checked })" />
                {{ t('admin.staffCards.bold') }}
              </label>
            </template>
            <BaseSelect v-if="selected.type === 'photo'" :model-value="selected.shape ?? 'rounded'" :options="shapeOptions" :label="t('admin.staffCards.shape')" @update:model-value="patch({ shape: $event as CardShape })" />
            <div v-if="selected.type === 'signature'" class="space-y-1.5">
              <p class="text-xs text-neutral-500">{{ isStudent ? t('admin.staffCards.studentSignatureHint') : t('admin.staffCards.signatureHint') }}</p>
              <div class="flex flex-wrap items-center gap-2">
                <label class="cursor-pointer rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                  {{ t('admin.staffCards.uploadSignature') }}
                  <input type="file" accept="image/*" class="hidden" @change="pickPhoto('signature', $event)" />
                </label>
                <button v-if="photoUrl('signature')" type="button" class="text-sm font-medium text-danger-600" @click="removePhoto('signature')">{{ t('admin.staffCards.removeBackground') }}</button>
              </div>
            </div>
            <p v-if="selected.type === 'logo' && !saved?.school_logo_url" class="text-xs text-neutral-500">{{ t('admin.staffCards.logoHint') }}</p>
            <p v-if="selected.type === 'expiry_date'" class="text-xs text-neutral-500">{{ t('admin.staffCards.expiryHint') }}</p>
            <div class="flex items-center justify-between gap-2 border-t border-neutral-100 pt-3">
              <button type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="duplicateSelected">{{ t('admin.staffCards.duplicate') }}</button>
              <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removeSelected">{{ t('admin.staffCards.removeField') }}</button>
            </div>
            <p class="hidden text-xs text-neutral-400 lg:block">{{ t('admin.staffCards.keysHint') }}</p>
          </div>
        </aside>
      </div>
    </template>
  </div>
</template>
