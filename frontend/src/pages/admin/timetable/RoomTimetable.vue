<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import TimetableGrid from '@/components/admin/TimetableGrid.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { timetableService, type TimetableOptions, type TimetableSlot } from '@/services/timetable'
import { ApiRequestError } from '@/types/api'

/**
 * Timetable > Room: who uses a room when — its classes' times, plus times of
 * other classes moved into it. Room assignment itself is on the class (its
 * room) or on one time (Class tab).
 */
const { t } = useI18n()

const options = ref<TimetableOptions>({ classes: [], teachers: [], rooms: [], subjects: [], me: null })
const roomId = ref('')
const slots = ref<TimetableSlot[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const roomOptions = computed(() => options.value.rooms.map((r) => ({ value: String(r.id), label: r.name })))
const roomName = computed(() => options.value.rooms.find((r) => String(r.id) === roomId.value)?.name ?? '')

async function load() {
  if (!roomId.value) return
  loading.value = true
  error.value = null
  try {
    slots.value = (await timetableService.forRoom(Number(roomId.value))).slots
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('timetable.loadFailed')
  } finally {
    loading.value = false
  }
}
function onRoom(value: string) {
  roomId.value = value
  void load()
}
const printPage = () => window.print()

onMounted(async () => {
  try {
    options.value = await timetableService.options()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('timetable.loadFailed')
  }
  if (options.value.rooms.length) {
    roomId.value = String(options.value.rooms[0]!.id)
    await load()
  }
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
      <BaseSelect class="w-64 max-w-full" :model-value="roomId" :options="roomOptions" :placeholder="t('timetable.chooseRoom')" @update:model-value="onRoom" />
      <BaseSpinner v-if="loading" class="h-4 w-4" />
      <BaseButton v-if="roomId" class="ml-auto" variant="outline" @click="printPage">{{ t('timetable.print') }}</BaseButton>
    </div>
    <BaseAlert v-if="error" variant="danger" class="mb-4 print:hidden">{{ error }}</BaseAlert>
    <BaseAlert v-if="!error && options.rooms.length === 0" variant="info" class="mb-4">{{ t('timetable.noRooms') }}</BaseAlert>
    <p class="mb-3 text-xs text-neutral-500 print:hidden">{{ t('timetable.roomHint') }}</p>

    <div v-if="roomId" class="print-area">
      <p class="mb-3 hidden text-lg font-semibold print:block">{{ t('timetable.roomTitle', { room: roomName }) }}</p>
      <TimetableGrid :slots="slots" :show="['class', 'subject', 'teacher']" />
    </div>
  </div>
</template>
