<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import OrgChartNode from '@/components/admin/OrgChartNode.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { organizationChartService, type ChartNode, type ChartStaff } from '@/services/organizationChart'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Organization Management > Reporting manager: who reports to whom,
 * from each staff member's "Reporting manager" (set on the staff form). The
 * top of each chain is someone with no manager (or whose manager no longer
 * works here); people with neither a manager nor any reports are listed
 * apart, so the tree itself only shows real reporting lines.
 */
const { t } = useI18n()

const staff = ref<ChartStaff[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const search = ref('')

const byManager = computed(() => {
  const map = new Map<number, ChartStaff[]>()
  for (const person of staff.value) {
    if (person.reports_to_staff_id === null) continue
    map.set(person.reports_to_staff_id, [...(map.get(person.reports_to_staff_id) ?? []), person])
  }
  return map
})

function node(person: ChartStaff, seen: Set<number>): ChartNode {
  // `seen` guards against a loop in old data (the staff form refuses new ones).
  const next = new Set(seen).add(person.id)
  const reports = (byManager.value.get(person.id) ?? []).filter((report) => !next.has(report.id))
  return {
    key: `s${person.id}`,
    kind: 'staff',
    label: person.full_name,
    sublabel: person.position,
    staff: person,
    children: reports.map((report) => node(report, next)),
  }
}

const ids = computed(() => new Set(staff.value.map((person) => person.id)))
const tops = computed(() => staff.value.filter((person) => person.reports_to_staff_id === null || !ids.value.has(person.reports_to_staff_id)))

const tree = computed(() => tops.value.filter((person) => byManager.value.has(person.id)).map((person) => node(person, new Set())))
const withoutManager = computed(() => tops.value.filter((person) => !byManager.value.has(person.id)))

// Search keeps any chain containing a match, so the match is seen in context.
function matches(n: ChartNode, term: string): boolean {
  return n.label.toLowerCase().includes(term) || (n.staff?.employee_code.toLowerCase().includes(term) ?? false) || n.children.some((child) => matches(child, term))
}

const term = computed(() => search.value.trim().toLowerCase())
const visibleTree = computed(() => (term.value ? tree.value.filter((n) => matches(n, term.value)) : tree.value))
const visibleWithoutManager = computed(() =>
  (term.value ? withoutManager.value.filter((person) => matches(node(person, new Set()), term.value)) : withoutManager.value).map((person) =>
    node(person, new Set()),
  ),
)

onMounted(async () => {
  try {
    staff.value = (await organizationChartService.get()).staff
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.organization.loadFailed')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-neutral-500">{{ t('admin.organization.reportingHint') }}</p>
      <input
        v-model="search"
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-xs rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
      />
    </div>

    <BaseSpinner v-if="loading" class="mx-auto mt-8" />
    <BaseAlert v-else-if="error" variant="danger">{{ error }}</BaseAlert>

    <template v-else>
      <p v-if="visibleTree.length === 0" class="rounded-lg border border-neutral-200 p-6 text-center text-sm text-neutral-400">
        {{ t('admin.organization.reportingEmpty') }}
      </p>
      <ul v-else class="rounded-lg border border-neutral-200 bg-white p-2">
        <OrgChartNode v-for="item in visibleTree" :key="item.key" :node="item" />
      </ul>

      <template v-if="visibleWithoutManager.length > 0">
        <h2 class="mb-2 mt-8 text-sm font-semibold text-neutral-800">
          {{ t('admin.organization.noReportingLine') }}
          <span class="font-normal text-neutral-400">({{ visibleWithoutManager.length }})</span>
        </h2>
        <ul class="rounded-lg border border-neutral-200 bg-white p-2">
          <OrgChartNode v-for="item in visibleWithoutManager" :key="item.key" :node="item" />
        </ul>
      </template>
    </template>
  </div>
</template>
