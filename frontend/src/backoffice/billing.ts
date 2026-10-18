import { formatMoney } from '@/utils/currency'

import type { BillingFlag, Plan, PlanModule, Subscription } from './api'

type T = (key: string, values?: Record<string, unknown>) => string

/** "$50.00 per month", "៛200,000 every 3 months", "$0.00 once, no end". */
export function priceLabel(plan: Pick<Plan, 'price' | 'currency' | 'period_months'>, t: T): string {
  const money = formatMoney(Number(plan.price), plan.currency)
  const period =
    plan.period_months === null
      ? t('bo.billing.plans.once')
      : plan.period_months === 1
        ? t('bo.billing.plans.monthly')
        : plan.period_months === 12
          ? t('bo.billing.plans.yearly')
          : t('bo.billing.plans.every', { n: plan.period_months })
  return `${money} ${period}`
}

export function studentsLabel(max: number | null, t: T): string {
  return max === null ? t('bo.billing.plans.noLimit') : t('bo.billing.plans.students', { n: max.toLocaleString() })
}

/** "12 days left", "Ends today", "Ended 3 days ago", "No end". */
export function endLabel(sub: Pick<Subscription, 'end_on' | 'days_left'>, t: T): string {
  if (sub.end_on === null) return t('bo.billing.school.noEnd')
  if (sub.days_left === null) return ''
  if (sub.days_left > 0) return t('bo.billing.school.daysLeft', { n: sub.days_left })
  if (sub.days_left === 0) return t('bo.billing.school.endsToday')
  return t('bo.billing.school.overdue', { n: -sub.days_left })
}

export function moduleLabel(module: PlanModule, locale: string): string {
  return locale === 'km' && module.label_km ? module.label_km : module.label
}

export const flagVariant: Record<BillingFlag, 'neutral' | 'success' | 'warning' | 'danger'> = {
  none: 'success',
  expiring: 'warning',
  expired: 'danger',
  cancelled: 'neutral',
  no_plan: 'neutral',
}
