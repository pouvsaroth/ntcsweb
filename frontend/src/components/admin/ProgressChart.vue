<script setup lang="ts">
import { computed, ref } from 'vue'

/**
 * Results over terms (Student Progress, docs/academics/gradebook.md 7d): one
 * 2px line per series on a fixed 0–100 % axis, ≥8px markers with a surface
 * ring, recessive grid, legend above, direct labels at the line ends, and a
 * crosshair + tooltip on hover. Colours are the validated categorical palette
 * in its fixed order (the dataviz skill's reference instance) — a series keeps
 * its slot, never re-coloured by rank. Callers keep it to a few series; the
 * full numbers are always in the table under the chart.
 */
interface ChartSeries {
  key: string
  name: string
  values: (number | null)[]
}

const props = defineProps<{ labels: string[]; series: ChartSeries[]; unit?: string; chartLabel: string }>()

/** Categorical slots 1–8, light mode (validated order — do not reshuffle). */
const PALETTE = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948']
const colour = (i: number) => PALETTE[i % PALETTE.length]!

const W = 640
const H = 260
const PAD = { top: 16, right: 96, bottom: 36, left: 40 }
const innerW = W - PAD.left - PAD.right
const innerH = H - PAD.top - PAD.bottom
const unit = computed(() => props.unit ?? '%')

const x = (i: number) => PAD.left + (props.labels.length <= 1 ? innerW / 2 : (i * innerW) / (props.labels.length - 1))
const y = (v: number) => PAD.top + innerH - (Math.max(0, Math.min(100, v)) / 100) * innerH
const ticks = [0, 25, 50, 75, 100]

/** Line path with gaps where a value is missing. */
function path(values: (number | null)[]): string {
  let d = ''
  let pen = false
  values.forEach((v, i) => {
    if (v === null) {
      pen = false
      return
    }
    d += `${pen ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)} `
    pen = true
  })
  return d.trim()
}

/** The last known value of a series, for its direct label. */
function lastPoint(values: (number | null)[]): { i: number; v: number } | null {
  for (let i = values.length - 1; i >= 0; i--) if (values[i] !== null) return { i, v: values[i]! }
  return null
}

// Direct labels at the right end, nudged apart so they never overlap.
const endLabels = computed(() => {
  const items = props.series
    .map((s, idx) => ({ idx, name: s.name, p: lastPoint(s.values) }))
    .filter((it) => it.p !== null)
    .map((it) => ({ idx: it.idx, name: it.name, value: it.p!.v, y: y(it.p!.v) }))
    .sort((a, b) => a.y - b.y)
  for (let k = 1; k < items.length; k++) if (items[k]!.y - items[k - 1]!.y < 13) items[k]!.y = items[k - 1]!.y + 13
  return items
})

const hover = ref<number | null>(null)
const svgRef = ref<SVGSVGElement | null>(null)
function onMove(event: PointerEvent) {
  const svg = svgRef.value
  if (!svg || props.labels.length === 0) return
  const rect = svg.getBoundingClientRect()
  const sx = ((event.clientX - rect.left) / rect.width) * W
  let best = 0
  for (let i = 1; i < props.labels.length; i++) if (Math.abs(x(i) - sx) < Math.abs(x(best) - sx)) best = i
  hover.value = best
}
const tooltipLeft = computed(() => (hover.value === null ? 0 : (x(hover.value) / W) * 100))
const fmt = (v: number | null) => (v === null ? '—' : `${Math.round(v * 10) / 10}${unit.value}`)
</script>

<template>
  <div>
    <!-- Legend: always for two or more series; identity never by colour alone (names beside the swatches). -->
    <ul v-if="series.length > 1" class="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-neutral-600">
      <li v-for="(s, i) in series" :key="s.key" class="flex items-center gap-1.5">
        <span class="inline-block h-0.5 w-4 rounded" :style="{ background: colour(i) }" />{{ s.name }}
      </li>
    </ul>

    <div class="relative">
      <svg
        ref="svgRef"
        :viewBox="`0 0 ${W} ${H}`"
        class="h-auto w-full touch-none select-none"
        role="img"
        :aria-label="chartLabel"
        @pointermove="onMove"
        @pointerleave="hover = null"
      >
        <!-- Recessive grid and axis -->
        <g v-for="tk in ticks" :key="tk">
          <line :x1="PAD.left" :x2="W - PAD.right" :y1="y(tk)" :y2="y(tk)" stroke="#e5e7eb" stroke-width="1" />
          <text :x="PAD.left - 6" :y="y(tk) + 4" text-anchor="end" font-size="11" fill="#6b7280">{{ tk }}{{ unit }}</text>
        </g>
        <text v-for="(l, i) in labels" :key="l + i" :x="x(i)" :y="H - 12" text-anchor="middle" font-size="11" fill="#6b7280">{{ l }}</text>

        <!-- Crosshair -->
        <line v-if="hover !== null" :x1="x(hover)" :x2="x(hover)" :y1="PAD.top" :y2="PAD.top + innerH" stroke="#9ca3af" stroke-width="1" stroke-dasharray="3 3" />

        <!-- Series -->
        <g v-for="(s, i) in series" :key="s.key">
          <path :d="path(s.values)" fill="none" :stroke="colour(i)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
          <template v-for="(v, j) in s.values" :key="j">
            <circle v-if="v !== null" :cx="x(j)" :cy="y(v)" :r="hover === j ? 5.5 : 4" :fill="colour(i)" stroke="#ffffff" stroke-width="2" />
          </template>
        </g>

        <!-- Direct labels at the line ends (text in ink, a dot carries the colour) -->
        <g v-for="lab in endLabels" :key="lab.idx">
          <circle :cx="W - PAD.right + 10" :cy="lab.y - 3" r="3" :fill="colour(lab.idx)" />
          <text :x="W - PAD.right + 17" :y="lab.y + 1" font-size="11" fill="#374151">{{ lab.name.length > 12 ? lab.name.slice(0, 11) + '…' : lab.name }}</text>
        </g>
      </svg>

      <!-- Tooltip -->
      <div
        v-if="hover !== null"
        class="pointer-events-none absolute top-1 z-10 min-w-36 -translate-x-1/2 rounded-lg border border-neutral-200 bg-white px-3 py-2 text-xs shadow-md"
        :style="{ left: `${Math.min(80, Math.max(20, tooltipLeft))}%` }"
      >
        <p class="mb-1 font-semibold text-neutral-900">{{ labels[hover] }}</p>
        <p v-for="(s, i) in series" :key="s.key" class="flex items-center justify-between gap-3 text-neutral-700">
          <span class="flex items-center gap-1.5"><span class="inline-block h-2 w-2 rounded-full" :style="{ background: colour(i) }" />{{ s.name }}</span>
          <span class="font-medium tabular-nums text-neutral-900">{{ fmt(s.values[hover] ?? null) }}</span>
        </p>
      </div>
    </div>
  </div>
</template>
