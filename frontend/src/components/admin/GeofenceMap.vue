<script setup lang="ts">
import 'leaflet/dist/leaflet.css'

import L from 'leaflet'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import type { Geofence, LatLng } from '@/services/timeAttendance'

/**
 * A map of check-in places (Leaflet + OpenStreetMap / satellite tiles).
 * Shows `zones` (faded unless `highlightId`), plus the one being edited:
 * a circle — tap the map or drag the pin to move it — or a polygon — tap
 * to add a corner, drag a corner to move it, tap a corner to remove it.
 * `me` is the viewer's own position (a blue dot).
 */
interface Props {
  zones?: Geofence[]
  highlightId?: number | null
  editable?: boolean
  shape?: 'circle' | 'polygon'
  center?: LatLng | null
  radius?: number
  points?: LatLng[]
  me?: { lat: number; lng: number; accuracy?: number | null } | null
  height?: string
}

const props = withDefaults(defineProps<Props>(), {
  zones: () => [],
  highlightId: null,
  editable: false,
  shape: 'circle',
  center: null,
  radius: 100,
  points: () => [],
  me: null,
  height: '360px',
})

const emit = defineEmits<{ 'update:center': [value: LatLng]; 'update:points': [value: LatLng[]] }>()

const { t } = useI18n()

/** Phnom Penh, when there is nothing to show yet. */
const DEFAULT_CENTER: LatLng = [11.5564, 104.9282]

const el = ref<HTMLDivElement | null>(null)
const satellite = ref(false)
let map: L.Map | null = null
let streetLayer: L.TileLayer | null = null
let satelliteLayer: L.TileLayer | null = null
const zonesLayer = L.layerGroup()
const editLayer = L.layerGroup()
const meLayer = L.layerGroup()

const PRIMARY = '#2563eb'
const ZONE = '#16a34a'

const pinIcon = L.divIcon({
  className: '',
  html: `<div style="width:22px;height:22px;border-radius:9999px;background:${PRIMARY};border:3px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>`,
  iconSize: [22, 22],
  iconAnchor: [11, 11],
})
const cornerIcon = L.divIcon({
  className: '',
  html: `<div style="width:16px;height:16px;border-radius:9999px;background:#fff;border:3px solid ${PRIMARY};box-shadow:0 1px 3px rgba(0,0,0,.35)"></div>`,
  iconSize: [16, 16],
  iconAnchor: [8, 8],
})

function zoneShape(zone: Geofence, style: L.PathOptions): L.Path {
  return zone.shape === 'polygon' && zone.points
    ? L.polygon(zone.points, style)
    : L.circle([zone.latitude, zone.longitude], { ...style, radius: zone.radius_m ?? 0 })
}

function drawZones() {
  zonesLayer.clearLayers()
  for (const zone of props.zones) {
    const strong = props.highlightId === null || props.highlightId === zone.id
    const color = zone.is_active ? ZONE : '#9ca3af'
    zoneShape(zone, { color, weight: strong ? 2 : 1, fillOpacity: strong ? 0.18 : 0.06, opacity: strong ? 0.9 : 0.4, dashArray: zone.is_active ? undefined : '6 6' })
      .bindTooltip(zone.name, { permanent: strong && props.zones.length <= 12, direction: 'center', className: 'geofence-label' })
      .addTo(zonesLayer)
  }
}

function drawEdit() {
  editLayer.clearLayers()
  if (!props.editable) return
  if (props.shape === 'circle') {
    if (!props.center) return
    L.circle(props.center, { radius: props.radius || 0, color: PRIMARY, weight: 2, fillOpacity: 0.15 }).addTo(editLayer)
    const pin = L.marker(props.center, { icon: pinIcon, draggable: true, autoPan: true }).addTo(editLayer)
    pin.on('dragend', () => {
      const p = pin.getLatLng()
      emit('update:center', [p.lat, p.lng])
    })
    return
  }
  if (props.points.length >= 2) {
    const style = { color: PRIMARY, weight: 2, fillOpacity: 0.15 }
    ;(props.points.length >= 3 ? L.polygon(props.points, style) : L.polyline(props.points, style)).addTo(editLayer)
  }
  props.points.forEach((point, index) => {
    const corner = L.marker(point, { icon: cornerIcon, draggable: true, autoPan: true }).addTo(editLayer)
    corner.on('dragend', () => {
      const p = corner.getLatLng()
      emit('update:points', props.points.map((q, i) => (i === index ? [p.lat, p.lng] : q)))
    })
    corner.on('click', () => emit('update:points', props.points.filter((_, i) => i !== index)))
  })
}

function drawMe() {
  meLayer.clearLayers()
  if (!props.me) return
  if (props.me.accuracy) L.circle([props.me.lat, props.me.lng], { radius: props.me.accuracy, color: '#3b82f6', weight: 1, fillOpacity: 0.08, interactive: false }).addTo(meLayer)
  L.circleMarker([props.me.lat, props.me.lng], { radius: 7, color: '#fff', weight: 3, fillColor: '#3b82f6', fillOpacity: 1 }).addTo(meLayer)
}

function zoneBounds(zone: Geofence): L.LatLngBounds {
  return zone.shape === 'polygon' && zone.points ? L.latLngBounds(zone.points) : L.latLng(zone.latitude, zone.longitude).toBounds((zone.radius_m ?? 50) * 2)
}

/** Fits the map to what is being edited, else the zones, else the viewer. */
function fitAll() {
  if (!map) return
  const bounds = L.latLngBounds([])
  if (props.editable && props.shape === 'circle' && props.center) bounds.extend(L.latLng(props.center).toBounds((props.radius || 50) * 2.4))
  if (props.editable && props.shape === 'polygon') props.points.forEach((p) => bounds.extend(p))
  if (!bounds.isValid()) {
    const zones = props.highlightId !== null ? props.zones.filter((z) => z.id === props.highlightId) : props.zones
    zones.forEach((zone) => bounds.extend(zoneBounds(zone)))
  }
  if (props.me) bounds.extend([props.me.lat, props.me.lng])
  if (bounds.isValid()) map.fitBounds(bounds, { padding: [24, 24], maxZoom: 18 })
  else map.setView(DEFAULT_CENTER, 13)
}

function setSatellite(on: boolean) {
  satellite.value = on
  if (!map || !streetLayer || !satelliteLayer) return
  map.removeLayer(on ? streetLayer : satelliteLayer)
  ;(on ? satelliteLayer : streetLayer).addTo(map)
}

/** Moves the map to a place (after "Use my location" or a pasted link). */
function flyTo(point: LatLng, zoom = 18) {
  map?.setView(point, zoom)
}

defineExpose({ fit: fitAll, flyTo })

onMounted(() => {
  if (!el.value) return
  map = L.map(el.value, { zoomControl: true, attributionControl: true, doubleClickZoom: !props.editable })
  streetLayer = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
  })
  satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxZoom: 19,
    attribution: 'Imagery &copy; Esri',
  })
  ;(satellite.value ? satelliteLayer : streetLayer).addTo(map)
  zonesLayer.addTo(map)
  editLayer.addTo(map)
  meLayer.addTo(map)
  map.on('click', (e: L.LeafletMouseEvent) => {
    if (!props.editable) return
    const point: LatLng = [e.latlng.lat, e.latlng.lng]
    if (props.shape === 'circle') emit('update:center', point)
    else emit('update:points', [...props.points, point])
  })
  drawZones()
  drawEdit()
  drawMe()
  fitAll()
  // A map opened inside a modal measures itself before the modal has its size.
  setTimeout(() => {
    map?.invalidateSize()
    fitAll()
  }, 250)
})

onBeforeUnmount(() => {
  map?.remove()
  map = null
})

watch(() => [props.zones, props.highlightId], drawZones, { deep: true })
watch(() => [props.editable, props.shape, props.center, props.radius, props.points], drawEdit, { deep: true })
watch(() => props.me, drawMe, { deep: true })
watch(() => props.highlightId, fitAll)
</script>

<template>
  <div class="relative isolate overflow-hidden rounded-[--radius-card] border border-neutral-200">
    <div ref="el" :style="{ height }" class="z-0 w-full" />
    <div class="absolute right-2 top-2 z-[500] flex overflow-hidden rounded-md border border-neutral-300 bg-white text-xs font-medium shadow">
      <button type="button" class="px-2.5 py-1.5" :class="!satellite ? 'bg-primary-600 text-white' : 'text-neutral-700'" @click="setSatellite(false)">{{ t('admin.timeAttendance.geofences.mapStreet') }}</button>
      <button type="button" class="px-2.5 py-1.5" :class="satellite ? 'bg-primary-600 text-white' : 'text-neutral-700'" @click="setSatellite(true)">{{ t('admin.timeAttendance.geofences.mapSatellite') }}</button>
    </div>
  </div>
</template>

<style>
.geofence-label {
  background: rgba(255, 255, 255, 0.9);
  border: 0;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
  font-weight: 600;
  font-size: 11px;
  padding: 1px 6px;
}
.geofence-label::before {
  display: none;
}
</style>
