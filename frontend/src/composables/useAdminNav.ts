import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { applyMenuLayout, type LaidOutGroup, type LaidOutItem } from '@/router/menuLayout'
import { useAuthStore } from '@/stores/auth'

/**
 * The admin menu as this customer's organization type lays it out (Back
 * office > Organization types > Menu layout), with each group / item's
 * display name. The sidebar, header and phone menu all read this.
 */
export function useAdminNav() {
  const auth = useAuthStore()
  const { t, locale } = useI18n()
  const nav = computed(() => applyMenuLayout(auth.menuLayout, locale.value))
  const groupLabel = (group: Pick<LaidOutGroup, 'label' | 'labelKey'>) => group.label ?? t(group.labelKey)
  const itemLabel = (item: Pick<LaidOutItem, 'label' | 'labelKey'>) => item.label ?? t(item.labelKey)
  return { nav, groupLabel, itemLabel }
}
