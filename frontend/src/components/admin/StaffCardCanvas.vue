<script setup lang="ts">
import QRCode from 'qrcode'
import { computed, onBeforeUnmount, ref, watch } from 'vue'

import { isImageField, PT_TO_MM, type CardField, type CardValues } from '@/utils/staffCard'

/**
 * One side of a staff card, drawn at `scale` px per mm. In editable mode a
 * field can be dragged to move it, resized from its corner handle, and new
 * fields can be dropped onto the card from the field list (drag data
 * "text/staff-card-field" = the field type).
 */
const props = withDefaults(
  defineProps<{
    widthMm: number
    heightMm: number
    background: string | null
    fields: CardField[]
    values: CardValues
    scale: number
    editable?: boolean
    selectedId?: string | null
    /** Text shown on an empty image box / empty text field while designing. */
    placeholder?: (field: CardField) => string
  }>(),
  { editable: false, selectedId: null, placeholder: undefined },
)

const emit = defineEmits<{
  select: [id: string | null]
  change: [field: CardField]
  drop: [type: string, xMm: number, yMm: number]
}>()

const root = ref<HTMLElement | null>(null)
const px = (mm: number) => `${mm * props.scale}px`
const snap = (mm: number) => Math.round(mm * 2) / 2

// QR codes as images, regenerated when the encoded text changes.
const qrImage = ref<string | null>(null)
watch(
  () => props.values.qr,
  async (text) => {
    qrImage.value = text ? await QRCode.toDataURL(text, { margin: 0, width: 300, errorCorrectionLevel: 'M' }).catch(() => null) : null
  },
  { immediate: true },
)

function imageFor(field: CardField): string | null {
  if (field.type === 'qr') return qrImage.value
  return props.values[field.type] ?? null
}

function textFor(field: CardField): string {
  if (field.type === 'text') return field.text ?? ''
  return props.values[field.type] ?? ''
}

function fieldStyle(field: CardField) {
  const style: Record<string, string> = { left: px(field.x), top: px(field.y), width: px(field.w), height: px(field.h) }
  if (!isImageField(field.type)) {
    style.fontSize = px((field.font_size ?? 8) * PT_TO_MM)
    style.color = field.color ?? '#111111'
    style.fontWeight = field.bold ? '700' : '400'
    style.textAlign = field.align ?? 'left'
    style.justifyContent = field.align === 'center' ? 'center' : field.align === 'right' ? 'flex-end' : 'flex-start'
  }
  return style
}

const imageRadius = (field: CardField) => (field.type !== 'photo' || field.shape === 'rect' || !field.shape ? '0' : field.shape === 'circle' ? '9999px' : px(2))

// ---- Move / resize --------------------------------------------------------
let drag: { field: CardField; mode: 'move' | 'resize'; startX: number; startY: number; orig: CardField } | null = null

function onPointerDown(event: PointerEvent, field: CardField, mode: 'move' | 'resize') {
  if (!props.editable) return
  event.preventDefault()
  event.stopPropagation()
  emit('select', field.id)
  drag = { field, mode, startX: event.clientX, startY: event.clientY, orig: { ...field } }
  window.addEventListener('pointermove', onPointerMove)
  window.addEventListener('pointerup', onPointerUp, { once: true })
}

function onPointerMove(event: PointerEvent) {
  if (!drag) return
  const dx = (event.clientX - drag.startX) / props.scale
  const dy = (event.clientY - drag.startY) / props.scale
  const o = drag.orig
  const next: CardField = { ...drag.field }
  if (drag.mode === 'move') {
    next.x = snap(Math.min(Math.max(o.x + dx, -o.w / 2), props.widthMm - o.w / 2))
    next.y = snap(Math.min(Math.max(o.y + dy, -o.h / 2), props.heightMm - o.h / 2))
  } else {
    next.w = Math.max(snap(o.w + dx), 2)
    next.h = Math.max(snap(o.h + dy), 2)
    // Square things stay square (QR codes, round photos).
    if (o.type === 'qr' || (o.type === 'photo' && o.shape === 'circle')) next.h = next.w
  }
  if (next.x !== drag.field.x || next.y !== drag.field.y || next.w !== drag.field.w || next.h !== drag.field.h) {
    drag.field = next
    emit('change', next)
  }
}

function onPointerUp() {
  drag = null
  window.removeEventListener('pointermove', onPointerMove)
}
onBeforeUnmount(onPointerUp)

// ---- Drop a new field from the list ----------------------------------------
const dropping = ref(false)
function onDragOver(event: DragEvent) {
  if (!props.editable || !event.dataTransfer?.types.includes('text/staff-card-field')) return
  event.preventDefault()
  event.dataTransfer.dropEffect = 'copy'
  dropping.value = true
}
function onDrop(event: DragEvent) {
  dropping.value = false
  const type = event.dataTransfer?.getData('text/staff-card-field')
  if (!props.editable || !type || !root.value) return
  event.preventDefault()
  const rect = root.value.getBoundingClientRect()
  emit('drop', type, (event.clientX - rect.left) / props.scale, (event.clientY - rect.top) / props.scale)
}

const cardStyle = computed(() => ({
  width: px(props.widthMm),
  height: px(props.heightMm),
  backgroundImage: props.background ? `url("${props.background}")` : undefined,
}))
</script>

<template>
  <div
    ref="root"
    class="relative shrink-0 select-none overflow-hidden rounded-md bg-white bg-cover bg-center shadow-md ring-1"
    :class="dropping ? 'ring-2 ring-primary-500' : 'ring-neutral-300'"
    :style="cardStyle"
    @pointerdown.self="editable && emit('select', null)"
    @dragover="onDragOver"
    @dragleave="dropping = false"
    @drop="onDrop"
  >
    <div
      v-for="field in fields"
      :key="field.id"
      class="absolute flex items-center"
      :class="[
        editable ? 'cursor-move touch-none' : '',
        editable && field.id === selectedId ? 'outline outline-2 outline-primary-500' : editable ? 'outline-dashed outline-1 outline-neutral-400/70 hover:outline-primary-400' : '',
      ]"
      :style="fieldStyle(field)"
      :data-field="field.type"
      @pointerdown="onPointerDown($event, field, 'move')"
    >
      <template v-if="isImageField(field.type)">
        <img
          v-if="imageFor(field)"
          :src="imageFor(field)!"
          alt=""
          draggable="false"
          class="pointer-events-none h-full w-full"
          :class="field.type === 'photo' ? 'object-cover' : 'object-contain'"
          :style="{ borderRadius: imageRadius(field) }"
        />
        <span
          v-else-if="editable"
          class="pointer-events-none flex h-full w-full items-center justify-center bg-neutral-100/80 text-center text-[10px] leading-tight text-neutral-500"
          :style="{ borderRadius: imageRadius(field) }"
          >{{ placeholder?.(field) }}</span
        >
      </template>
      <span v-else class="pointer-events-none block w-full overflow-hidden leading-[1.15] break-words" :class="!textFor(field) && editable ? 'italic opacity-50' : ''">{{
        textFor(field) || (editable ? placeholder?.(field) : '')
      }}</span>

      <span
        v-if="editable && field.id === selectedId"
        class="absolute -right-1.5 -bottom-1.5 h-3 w-3 cursor-nwse-resize touch-none rounded-sm border border-white bg-primary-600"
        @pointerdown="onPointerDown($event, field, 'resize')"
      />
    </div>
  </div>
</template>
