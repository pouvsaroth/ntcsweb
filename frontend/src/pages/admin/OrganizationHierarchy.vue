<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import OrgChartNode from '@/components/admin/OrgChartNode.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { organizationChartService, type ChartNode, type ChartStaff, type OrganizationChart } from '@/services/organizationChart'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Organization Management > Organization hierarchy: Branch >
 * Department > Team, each with the working staff placed directly in it (a
 * person sits at the deepest level they were given). Units with no parent,
 * and people with no placement at all, are gathered in "Not assigned"
 * groups at the end rather than left out.
 */
const { t } = useI18n()

const chart = ref<OrganizationChart | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

function personNode(staff: ChartStaff): ChartNode {
  return { key: `s${staff.id}`, kind: 'staff', label: staff.full_name, sublabel: staff.position, staff, children: [] }
}

const tree = computed<ChartNode[]>(() => {
  const data = chart.value
  if (!data) return []

  const branchIds = new Set(data.branches.map((b) => b.id))
  const departmentIds = new Set(data.departments.map((d) => d.id))
  const teamIds = new Set(data.teams.map((team) => team.id))

  // Deepest placement wins — someone in a team is shown under that team only.
  const inTeam = (id: number) => data.staff.filter((s) => s.team_id === id)
  const inDepartment = (id: number) => data.staff.filter((s) => s.department_id === id && !(s.team_id && teamIds.has(s.team_id)))
  const inBranch = (id: number) =>
    data.staff.filter((s) => s.branch_id === id && !(s.department_id && departmentIds.has(s.department_id)) && !(s.team_id && teamIds.has(s.team_id)))

  const teamNode = (team: OrganizationChart['teams'][number]): ChartNode => ({
    key: `t${team.id}`,
    kind: 'team',
    label: team.name,
    sublabel: team.code,
    children: inTeam(team.id).map(personNode),
  })

  const departmentNode = (department: OrganizationChart['departments'][number]): ChartNode => ({
    key: `d${department.id}`,
    kind: 'department',
    label: department.name,
    sublabel: department.code,
    children: [...data.teams.filter((team) => team.department_id === department.id).map(teamNode), ...inDepartment(department.id).map(personNode)],
  })

  const nodes: ChartNode[] = data.branches.map((branch) => ({
    key: `b${branch.id}`,
    kind: 'branch',
    label: branch.name,
    sublabel: branch.code,
    children: [...data.departments.filter((d) => d.branch_id === branch.id).map(departmentNode), ...inBranch(branch.id).map(personNode)],
  }))

  const looseDepartments = data.departments.filter((d) => !d.branch_id || !branchIds.has(d.branch_id))
  const looseTeams = data.teams.filter((team) => !team.department_id || !departmentIds.has(team.department_id))
  const unplaced = data.staff.filter(
    (s) =>
      !(s.team_id && teamIds.has(s.team_id)) &&
      !(s.department_id && departmentIds.has(s.department_id)) &&
      !(s.branch_id && branchIds.has(s.branch_id)),
  )

  if (looseDepartments.length > 0 || looseTeams.length > 0 || unplaced.length > 0) {
    nodes.push({
      key: 'unassigned',
      kind: 'group',
      label: t('admin.organization.notAssigned'),
      children: [...looseDepartments.map(departmentNode), ...looseTeams.map(teamNode), ...unplaced.map(personNode)],
    })
  }

  return nodes
})

onMounted(async () => {
  try {
    chart.value = await organizationChartService.get()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.organization.loadFailed')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.organization.hierarchyHint') }}</p>

    <BaseSpinner v-if="loading" class="mx-auto mt-8" />
    <BaseAlert v-else-if="error" variant="danger">{{ error }}</BaseAlert>
    <p v-else-if="tree.length === 0" class="rounded-lg border border-neutral-200 p-6 text-center text-sm text-neutral-400">
      {{ t('admin.organization.hierarchyEmpty') }}
    </p>
    <ul v-else class="rounded-lg border border-neutral-200 bg-white p-2">
      <OrgChartNode v-for="node in tree" :key="node.key" :node="node" />
    </ul>
  </div>
</template>
