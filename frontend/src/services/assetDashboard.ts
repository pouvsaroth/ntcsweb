import { apiGet } from '@/services/http'
import type { AssetIssue } from '@/services/assetIssues'
import type { AssetMaintenance } from '@/services/assetMaintenance'
import type { Asset } from '@/services/assets'

export interface AssetDashboardSummary {
  counts_by_status: Record<string, number>
  counts_by_category: { category_id: number; category_name: string; total: number }[]
  counts_by_location: { location_id: number; location_name: string; total: number }[]
  total_investment: number
  total_repair_cost: number
  /** The same two figures in USD and in KHR, each record at the rate on its own date. */
  total_investment_usd: number
  total_investment_khr: number
  total_repair_cost_usd: number
  total_repair_cost_khr: number
  open_issues_count: number
  open_issues_by_priority: Record<string, number>
  open_repairs_count: number
  assignment_totals: { active: number; overdue: number }
  top_repair_shops: { repair_shop_id: number; repair_shop_name: string; repair_count: number; total_cost: number; total_cost_usd: number | null; total_cost_khr: number | null }[]
  recent_issues: AssetIssue[]
  upcoming_maintenance: AssetMaintenance[]
  warranty_expiring: Asset[]
}

export const assetDashboardService = {
  summary: () => apiGet<AssetDashboardSummary>('/assets/dashboard'),
}
