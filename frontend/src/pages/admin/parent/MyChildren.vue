<script setup lang="ts">
/** Parent portal home: each child with their class, and quick links to their pages. */
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import NavIcon from '@/components/layout/NavIcon.vue'
import { useParentChildStore } from '@/stores/parentChild'

const { t } = useI18n()
const router = useRouter()
const store = useParentChildStore()

const links = [
  { to: '/admin/my-scores', icon: 'chartBar', key: 'parentPortal.scores' },
  { to: '/admin/my-attendance', icon: 'checkCircle', key: 'parentPortal.attendance' },
  { to: '/admin/my-child-invoices', icon: 'document', key: 'parentPortal.invoices' },
  { to: '/admin/my-child-leave', icon: 'calendar', key: 'parentPortal.leave' },
]

function open(childId: number, to: string): void {
  store.select(childId)
  void router.push(to)
}
</script>

<template>
  <div>
    <h1 class="mb-1 text-xl font-bold text-neutral-900">{{ t('parentPortal.title') }}</h1>
    <p class="mb-5 text-sm text-neutral-500">{{ t('parentPortal.intro') }}</p>
    <p v-if="store.loaded && !store.children.length" class="rounded-[--radius-card] border border-dashed border-neutral-300 p-6 text-center text-sm text-neutral-500">
      {{ t('parentPortal.noChildren') }}
    </p>
    <div class="grid gap-4 md:grid-cols-2">
      <section v-for="c in store.children" :key="c.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <div class="flex items-center gap-3">
          <img v-if="c.photo_url" :src="c.photo_url" alt="" class="h-14 w-14 rounded-full object-cover" />
          <span v-else class="flex h-14 w-14 items-center justify-center rounded-full bg-primary-100 text-lg font-bold text-primary-700">{{ c.name.charAt(0) }}</span>
          <div class="min-w-0">
            <p class="truncate font-semibold text-neutral-900">{{ c.name }}</p>
            <p class="text-xs text-neutral-500">{{ c.student_code }}<template v-if="c.relation"> · {{ c.relation }}</template></p>
            <p class="text-xs text-neutral-600">{{ c.classes.length ? c.classes.join(', ') : t('parentPortal.noClass') }}</p>
          </div>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-2">
          <button
            v-for="l in links"
            :key="l.to"
            type="button"
            class="flex items-center gap-2 rounded-lg border border-neutral-200 px-3 py-2.5 text-left text-sm font-medium text-neutral-700 hover:border-primary-300 hover:bg-primary-50"
            @click="open(c.id, l.to)"
          >
            <NavIcon :name="l.icon" size="h-5 w-5" class="shrink-0 text-primary-600" />
            {{ t(l.key) }}
          </button>
        </div>
      </section>
    </div>
  </div>
</template>
