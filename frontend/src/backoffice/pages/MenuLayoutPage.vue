<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { onBeforeRouteLeave, RouterLink, useRoute } from 'vue-router'

import NavIcon from '@/components/layout/NavIcon.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import appEn from '@/i18n/locales/en'
import appKm from '@/i18n/locales/km'
import { adminNav } from '@/router/adminNav'
import { applyMenuLayout, MENU_LAYOUT_LANGS, nameIn, TAB_BY_PATH, TAB_CATALOG, type MenuLayout } from '@/router/menuLayout'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

import { organizationTypeName, organizationTypesApi, type OrganizationType } from '../api'
import { build, buildTabs, LANG_LABELS, newGroup, tabItem, toLayout, trimmed, type EdGroup, type EdItem } from '../menuLayoutEditor'
import { useSession } from '../session'

/**
 * Back office > Organization types > Menu layout: one type's own console
 * sidebar. Groups and items are moved, renamed or hidden, new groups added,
 * page tabs reordered or taken out of their page as a menu item. The
 * console applies it with the same catalog (router/menuLayout.ts); anything
 * not changed here keeps its standard place. Hiding only changes the menu.
 */
const { t, locale } = useI18n()
const route = useRoute()
const session = useSession()
const confirmDialog = useConfirmDialogStore()
const canWrite = computed(() => session.can('platform.school.write'))
const typeId = Number(route.params.id)


// ----- console labels (the back office has only the `common` part of the console's catalogue)

function lookup(catalog: unknown, key: string): string | undefined {
  const value = key.split('.').reduce<unknown>((node, part) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined), catalog)
  return typeof value === 'string' ? value : undefined
}
function consoleLabel(key: string): string {
  return (locale.value === 'km' ? lookup(appKm, key) : undefined) ?? lookup(appEn, key) ?? key
}


const groups = ref<EdGroup[]>([])
const tabOrder = ref<Record<string, string[]>>({})
const typeRow = ref<OrganizationType | null>(null)
const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)
const saveError = ref<ApiRequestError | null>(null)
const notice = ref<string | null>(null)
const savedSnapshot = ref('')


const tabBars = TAB_CATALOG
const currentLayout = () => toLayout(groups.value, tabOrder.value)
const snapshot = () => JSON.stringify(currentLayout())
const dirty = computed(() => !loading.value && snapshot() !== savedSnapshot.value)

function apply(layout: MenuLayout | null): void {
  groups.value = build(layout)
  tabOrder.value = buildTabs(layout)
  savedSnapshot.value = snapshot()
}

async function load(): Promise<void> {
  try {
    const [types, res] = await Promise.all([organizationTypesApi.list(), organizationTypesApi.menuLayout(typeId)])
    typeRow.value = types.find((type) => type.id === typeId) ?? null
    apply(res.menu_layout)
    error.value = null
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    loading.value = false
  }
}

async function save(layout: MenuLayout | null): Promise<void> {
  saving.value = true
  saveError.value = null
  notice.value = null
  try {
    const res = await organizationTypesApi.saveMenuLayout(typeId, layout)
    apply(res.menu_layout)
    notice.value = res.menu_layout ? t('bo.menuLayout.saved') : t('bo.menuLayout.savedStandard')
  } catch (e) {
    saveError.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    saving.value = false
  }
}

async function resetToStandard(): Promise<void> {
  if (!(await confirmDialog.confirm(t('bo.menuLayout.resetConfirm')))) return
  await save(null)
}

onBeforeRouteLeave(async () => (dirty.value ? await confirmDialog.confirm(t('bo.menuLayout.leaveConfirm')) : true))

// ----- editing

const groupName = (g: EdGroup) => nameIn(trimmed(g.names), locale.value) || (g.labelKey ? consoleLabel(g.labelKey) : t('bo.menuLayout.newGroup'))
const itemName = (i: EdItem) => nameIn(trimmed(i.names), locale.value) || consoleLabel(i.labelKey)
const standardName = (key: string | null) => (key ? consoleLabel(key) : '')

function move<T>(list: T[], index: number, by: -1 | 1): void {
  const to = index + by
  if (to < 0 || to >= list.length) return
  const [row] = list.splice(index, 1)
  list.splice(to, 0, row!)
}

const groupOptions = computed(() => groups.value.map((g) => ({ value: g.uid, label: groupName(g) })))

function moveItemTo(from: EdGroup, item: EdItem, groupUid: string): void {
  const target = groups.value.find((g) => g.uid === groupUid)
  if (!target || target === from) return
  from.items.splice(from.items.indexOf(item), 1)
  target.items.push(item)
}

function addGroup(): void {
  groups.value.push(newGroup())
}

/** A new group's items go back to their own group; its page tabs go back into their page. */
function removeGroup(group: EdGroup): void {
  for (const item of group.items) {
    if (item.from === 'tab') continue
    groups.value.find((g) => g.key === item.from)?.items.push(item)
  }
  groups.value.splice(groups.value.indexOf(group), 1)
}

const takenOut = computed(() => new Set(groups.value.flatMap((g) => g.items.filter((i) => i.from === 'tab').map((i) => i.to))))
const barTitle = (bar: (typeof tabBars)[number]) =>
  tabBars.filter((b) => b.labelKey === bar.labelKey).length > 1 ? `${consoleLabel(bar.labelKey)} · ${consoleLabel(bar.tabs[0]!.labelKey)}` : consoleLabel(bar.labelKey)
const tabOptions = computed(() =>
  tabBars.flatMap((bar) => bar.tabs.filter((tab) => !takenOut.value.has(tab.to)).map((tab) => ({ value: tab.to, label: `${barTitle(bar)} › ${consoleLabel(tab.labelKey)}` }))),
)

function addTab(group: EdGroup, to: string): void {
  const item = to ? tabItem(to) : null
  if (item) group.items.push(item)
}

function putTabBack(group: EdGroup, item: EdItem): void {
  group.items.splice(group.items.indexOf(item), 1)
}

const iconOptions = computed(() =>
  [...new Set(adminNav.flatMap((g) => [g.icon, ...g.items.map((i) => i.icon)]).filter((i): i is string => !!i))].sort().map((icon) => ({ value: icon, label: icon })),
)

// ----- preview: the sidebar as the console draws it (every item; users still see only what their plan and role allow)

const preview = computed(() =>
  applyMenuLayout(currentLayout(), locale.value)
    .filter((g) => !g.flat)
    // Platform staff, student and parent items: a customer's staff never see them.
    .map((g) => ({ ...g, items: g.items.filter((i) => !i.superAdminOnly && !i.studentOnly && !i.parentOnly) }))
    .filter((g) => g.items.length || g.labelKey.startsWith('custom:')),
)

const errorList = computed(() => [...new Set(Object.values(saveError.value?.errors ?? {}).flat())])

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div class="min-w-0">
        <RouterLink to="/organization-types" class="text-sm text-primary-700 hover:underline">← {{ t('bo.menuLayout.back') }}</RouterLink>
        <h1 class="mt-1 text-lg font-semibold text-neutral-900">
          {{ t('bo.menuLayout.title') }}<template v-if="typeRow"> · {{ organizationTypeName(typeRow, locale) }}</template>
        </h1>
        <p class="text-sm text-neutral-500">{{ t('bo.menuLayout.hint') }}</p>
      </div>
      <div v-if="canWrite && !loading && !error" class="flex flex-wrap gap-2">
        <BaseButton variant="ghost" :disabled="saving" @click="resetToStandard">{{ t('bo.menuLayout.reset') }}</BaseButton>
        <BaseButton :loading="saving" :disabled="!dirty" @click="save(currentLayout())">{{ t('bo.common.save') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="success" class="mb-4">{{ notice }}</BaseAlert>
    <BaseAlert v-if="saveError" variant="danger" class="mb-4">
      {{ saveError.message }}
      <ul v-if="errorList.length" class="mt-1 list-disc pl-5">
        <li v-for="msg in errorList" :key="msg">{{ msg }}</li>
      </ul>
    </BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <div v-else-if="!error" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
      <div class="space-y-6">
        <!-- Menu groups -->
        <section>
          <div class="mb-2 flex items-center justify-between gap-3">
            <h2 class="font-semibold text-neutral-900">{{ t('bo.menuLayout.groups') }}</h2>
            <BaseButton v-if="canWrite" size="sm" variant="outline" @click="addGroup">{{ t('bo.menuLayout.addGroup') }}</BaseButton>
          </div>
          <div class="space-y-3">
            <div
              v-for="(group, gi) in groups"
              :key="group.uid"
              class="rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card]"
              :class="{ 'opacity-60': group.hidden }"
            >
              <div class="flex flex-wrap items-center gap-2 p-3">
                <NavIcon :name="group.icon" />
                <div class="min-w-0 flex-1">
                  <p class="truncate font-medium text-neutral-900">{{ groupName(group) }}</p>
                  <p v-if="group.labelKey && groupName(group) !== standardName(group.labelKey)" class="truncate text-xs text-neutral-400">{{ standardName(group.labelKey) }}</p>
                </div>
                <BaseBadge v-if="!group.labelKey" variant="primary">{{ t('bo.menuLayout.newBadge') }}</BaseBadge>
                <BaseBadge v-if="group.hidden" variant="warning">{{ t('bo.menuLayout.hiddenBadge') }}</BaseBadge>
                <div v-if="canWrite" class="flex flex-wrap items-center gap-1">
                  <button type="button" class="mv" :disabled="gi === 0" :aria-label="t('bo.menuLayout.up')" @click="move(groups, gi, -1)">↑</button>
                  <button type="button" class="mv" :disabled="gi === groups.length - 1" :aria-label="t('bo.menuLayout.down')" @click="move(groups, gi, 1)">↓</button>
                  <BaseButton size="sm" variant="ghost" @click="group.hidden = !group.hidden">{{ group.hidden ? t('bo.menuLayout.show') : t('bo.menuLayout.hide') }}</BaseButton>
                  <BaseButton size="sm" variant="ghost" @click="group.open = !group.open">{{ t('bo.menuLayout.rename') }}</BaseButton>
                  <BaseButton v-if="!group.labelKey" size="sm" variant="ghost" @click="removeGroup(group)">{{ t('bo.menuLayout.removeGroup') }}</BaseButton>
                </div>
              </div>

              <div v-if="group.open && canWrite" class="grid gap-3 border-t border-neutral-100 p-3 sm:grid-cols-3">
                <BaseInput
                  v-for="lang in MENU_LAYOUT_LANGS"
                  :key="lang"
                  v-model="group.names[lang]"
                  :label="LANG_LABELS[lang]"
                  :placeholder="lang === 'en' && group.labelKey ? standardName(group.labelKey) : undefined"
                  :required="lang === 'en' && !group.labelKey"
                />
                <div class="flex items-end gap-2">
                  <BaseSelect v-model="group.icon" class="flex-1" :label="t('bo.menuLayout.icon')" :options="iconOptions" />
                  <span class="mb-2.5"><NavIcon :name="group.icon" /></span>
                </div>
              </div>

              <ul class="divide-y divide-neutral-100 border-t border-neutral-100">
                <li v-for="(item, ii) in group.items" :key="item.uid" class="px-3 py-2" :class="{ 'opacity-60': item.hidden }">
                  <div class="flex flex-wrap items-center gap-2">
                    <NavIcon :name="item.icon" size="h-4 w-4" />
                    <div class="min-w-0 flex-1">
                      <p class="truncate text-sm text-neutral-800">{{ itemName(item) }}</p>
                      <p v-if="itemName(item) !== consoleLabel(item.labelKey)" class="truncate text-xs text-neutral-400">{{ consoleLabel(item.labelKey) }}</p>
                    </div>
                    <BaseBadge v-if="item.from === 'tab'" variant="primary">{{ t('bo.menuLayout.tabBadge') }}</BaseBadge>
                    <BaseBadge v-else-if="item.from !== group.key" variant="neutral">{{ t('bo.menuLayout.movedFrom', { group: standardName(item.from) }) }}</BaseBadge>
                    <BaseBadge v-if="item.hidden" variant="warning">{{ t('bo.menuLayout.hiddenBadge') }}</BaseBadge>
                    <div v-if="canWrite" class="flex flex-wrap items-center gap-1">
                      <button type="button" class="mv" :disabled="ii === 0" :aria-label="t('bo.menuLayout.up')" @click="move(group.items, ii, -1)">↑</button>
                      <button type="button" class="mv" :disabled="ii === group.items.length - 1" :aria-label="t('bo.menuLayout.down')" @click="move(group.items, ii, 1)">↓</button>
                      <select
                        class="rounded-lg border border-neutral-300 bg-white py-1 pr-7 pl-2 text-xs text-neutral-700"
                        :aria-label="t('bo.menuLayout.moveTo')"
                        :value="''"
                        @change="moveItemTo(group, item, ($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = ''"
                      >
                        <option value="" disabled>{{ t('bo.menuLayout.moveTo') }}</option>
                        <option v-for="opt in groupOptions.filter((o) => o.value !== group.uid)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                      </select>
                      <BaseButton size="sm" variant="ghost" @click="item.hidden = !item.hidden">{{ item.hidden ? t('bo.menuLayout.show') : t('bo.menuLayout.hide') }}</BaseButton>
                      <BaseButton size="sm" variant="ghost" @click="item.open = !item.open">{{ t('bo.menuLayout.rename') }}</BaseButton>
                      <BaseButton v-if="item.from === 'tab'" size="sm" variant="ghost" @click="putTabBack(group, item)">{{ t('bo.menuLayout.putBack') }}</BaseButton>
                    </div>
                  </div>
                  <div v-if="item.open && canWrite" class="mt-2 grid gap-3 sm:grid-cols-3">
                    <BaseInput
                      v-for="lang in MENU_LAYOUT_LANGS"
                      :key="lang"
                      v-model="item.names[lang]"
                      :label="LANG_LABELS[lang]"
                      :placeholder="lang === 'en' ? consoleLabel(item.labelKey) : undefined"
                    />
                  </div>
                </li>
                <li v-if="!group.items.length" class="px-3 py-2 text-sm text-neutral-400">{{ t('bo.menuLayout.emptyGroup') }}</li>
              </ul>

              <div v-if="canWrite" class="border-t border-neutral-100 p-3">
                <select
                  class="w-full rounded-lg border border-neutral-300 bg-white py-1.5 pr-7 pl-2 text-sm text-neutral-700 sm:w-auto sm:max-w-md"
                  :aria-label="t('bo.menuLayout.addTab')"
                  :value="''"
                  @change="addTab(group, ($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = ''"
                >
                  <option value="" disabled>{{ t('bo.menuLayout.addTab') }}</option>
                  <option v-for="opt in tabOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
              </div>
            </div>
          </div>
        </section>

        <!-- Page tabs -->
        <section>
          <h2 class="font-semibold text-neutral-900">{{ t('bo.menuLayout.tabs') }}</h2>
          <p class="mb-2 text-sm text-neutral-500">{{ t('bo.menuLayout.tabsHint') }}</p>
          <div class="grid gap-3 sm:grid-cols-2">
            <div v-for="bar in tabBars" :key="bar.key" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
              <p class="mb-1 font-medium text-neutral-900">{{ barTitle(bar) }}</p>
              <ol class="space-y-1">
                <li v-for="(to, ti) in tabOrder[bar.key]" :key="to" class="flex items-center gap-2 text-sm">
                  <span class="w-5 text-right text-xs text-neutral-400">{{ ti + 1 }}.</span>
                  <span class="min-w-0 flex-1 truncate" :class="takenOut.has(to) ? 'text-neutral-400 line-through' : 'text-neutral-800'">
                    {{ consoleLabel(TAB_BY_PATH.get(to)?.labelKey ?? to) }}
                  </span>
                  <BaseBadge v-if="takenOut.has(to)" variant="primary">{{ t('bo.menuLayout.inMenu') }}</BaseBadge>
                  <template v-if="canWrite">
                    <button type="button" class="mv" :disabled="ti === 0" :aria-label="t('bo.menuLayout.up')" @click="move(tabOrder[bar.key]!, ti, -1)">↑</button>
                    <button type="button" class="mv" :disabled="ti === tabOrder[bar.key]!.length - 1" :aria-label="t('bo.menuLayout.down')" @click="move(tabOrder[bar.key]!, ti, 1)">↓</button>
                  </template>
                </li>
              </ol>
            </div>
          </div>
        </section>
      </div>

      <!-- Preview -->
      <aside class="lg:sticky lg:top-4 lg:self-start">
        <h2 class="font-semibold text-neutral-900">{{ t('bo.menuLayout.preview') }}</h2>
        <p class="mb-2 text-xs text-neutral-500">{{ t('bo.menuLayout.previewHint') }}</p>
        <nav class="max-h-[75vh] overflow-y-auto rounded-[--radius-card] border border-neutral-200 bg-white p-2 text-sm shadow-[--shadow-card]">
          <template v-for="group in preview" :key="group.labelKey">
            <template v-if="group.standalone">
              <p v-for="item in group.items" :key="item.to ?? item.labelKey" class="flex items-center gap-2 rounded px-2 py-1.5 font-medium text-neutral-700">
                <NavIcon :name="item.icon" size="h-4 w-4" />{{ item.label ?? consoleLabel(item.labelKey) }}
              </p>
            </template>
            <div v-else class="py-1">
              <p class="flex items-center gap-2 px-2 py-1.5 font-semibold text-neutral-800">
                <NavIcon :name="group.icon" size="h-4 w-4" />{{ group.label ?? (group.labelKey.startsWith('custom:') ? t('bo.menuLayout.newGroup') : consoleLabel(group.labelKey)) }}
              </p>
              <p v-for="item in group.items" :key="item.to ?? item.labelKey" class="flex items-center gap-2 py-1 pr-2 pl-7 text-neutral-600">
                <NavIcon :name="item.icon" size="h-3.5 w-3.5" />{{ item.label ?? consoleLabel(item.labelKey) }}
              </p>
            </div>
          </template>
        </nav>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.mv {
  border-radius: 0.375rem;
  border: 1px solid var(--color-neutral-200, #e5e5e5);
  padding: 0.125rem 0.5rem;
  font-size: 0.75rem;
  color: var(--color-neutral-600, #525252);
}
.mv:hover:not(:disabled) {
  background: var(--color-neutral-100, #f5f5f5);
}
.mv:disabled {
  opacity: 0.35;
}
</style>
