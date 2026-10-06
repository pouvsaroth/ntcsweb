import { apiGet } from '@/services/http'

/**
 * GET /organization/chart — the flat lists HRM > Organization Management's
 * Reporting manager and Organization hierarchy tabs build their trees from
 * (see OrganizationChartController). Active units and working staff only.
 */
export interface ChartUnit {
  id: number
  code: string
  name: string
}

export interface ChartStaff {
  id: number
  employee_code: string
  full_name: string
  position: string | null
  photo_url: string | null
  profile_color: string | null
  branch_id: number | null
  department_id: number | null
  team_id: number | null
  reports_to_staff_id: number | null
}

export interface OrganizationChart {
  branches: ChartUnit[]
  departments: (ChartUnit & { branch_id: number | null })[]
  teams: (ChartUnit & { department_id: number | null })[]
  staff: ChartStaff[]
}

/** One row of either tree — a unit (branch/department/team/group) or a person. */
export interface ChartNode {
  key: string
  kind: 'branch' | 'department' | 'team' | 'group' | 'staff'
  label: string
  sublabel?: string | null
  staff?: ChartStaff
  children: ChartNode[]
}

export const organizationChartService = {
  get: () => apiGet<OrganizationChart>('/organization/chart'),
}
