<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import RunList from '@/pages/admin/payroll/RunList.vue'

/**
 * HRM > Payroll > Payroll approval — payrolls waiting for approval (open one
 * to check its payslips and approve or reject it), then recently decided
 * ones. With an Approval Flow for payroll (Approval Flow > Flow Setting),
 * each step's group approves in turn.
 */
const { t } = useI18n()
</script>

<template>
  <div class="space-y-6">
    <section>
      <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.payroll.approval.waiting') }}</h2>
      <p class="mb-3 text-sm text-neutral-500">{{ t('admin.payroll.approval.hint') }}</p>
      <RunList statuses="pending" :empty="t('admin.payroll.approval.noneWaiting')" />
    </section>
    <section>
      <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.payroll.approval.decided') }}</h2>
      <RunList statuses="approved,rejected,paid" :empty="t('admin.payroll.approval.noneDecided')" />
    </section>
  </div>
</template>
