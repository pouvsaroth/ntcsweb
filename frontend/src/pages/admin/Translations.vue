<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { onBeforeRouteLeave } from 'vue-router'

import TranslationTreeNode from '@/components/admin/TranslationTreeNode.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { builtInMessage, LOCALE_NAMES, SUPPORTED_LOCALES, type Locale } from '@/i18n'
import { translationOverridesService, type TranslationChange, type TranslationOverrides } from '@/services/translationOverrides'
import { useAdminUiStore } from '@/stores/adminUi'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { buildTranslationTree, type TranslationNode } from '@/utils/translationTree'

/**
 * Settings > Language > Translation: the school rewords any of the admin
 * app's text, per language, for everyone in the school (see
 * TranslationOverrideController). Arranged like the sidebar — see
 * translationTree.ts. An empty box means "use the built-in text".
 */
const { t, locale: uiLocale } = useI18n()
const confirmDialog = useConfirmDialogStore()
const adminUi = useAdminUiStore()

const locale = ref<Locale>(uiLocale.value as Locale)
const localeOptions = SUPPORTED_LOCALES.map((code) => ({ value: code, label: LOCALE_NAMES[code] }))

const tree = buildTranslationTree()

const overrides = ref<TranslationOverrides | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const savedMessage = ref(false)
const saving = ref(false)

/** The selected language's boxes — rebuilt from the saved words on switching language or saving. */
const drafts = reactive<Record<string, string>>({})
const saved = computed<Record<string, string>>(() => overrides.value?.[locale.value] ?? {})

function resetDrafts() {
  for (const key of Object.keys(drafts)) delete drafts[key]
  Object.assign(drafts, saved.value)
}

const changes = computed<TranslationChange[]>(() => {
  const keys = new Set([...Object.keys(drafts), ...Object.keys(saved.value)])
  return [...keys]
    .filter((key) => (drafts[key] ?? '').trim() !== (saved.value[key] ?? ''))
    .map((key) => ({ locale: locale.value, key, value: (drafts[key] ?? '').trim() }))
})

async function changeLocale(next: string) {
  if (changes.value.length > 0 && !(await confirmDialog.confirm({ message: t('admin.translations.discardConfirm'), danger: true }))) return
  locale.value = next as Locale
  resetDrafts()
}

// --- Search / filter ------------------------------------------------------

const search = ref('')
const onlySchoolWords = ref(false)

function matchesWord(key: string, term: string): boolean {
  if (onlySchoolWords.value && (drafts[key] ?? '') === '' && (saved.value[key] ?? '') === '') return false
  if (!term) return true
  return [key, builtInMessage('en', key) ?? '', builtInMessage(locale.value, key) ?? '', drafts[key] ?? ''].some((text) => text.toLowerCase().includes(term))
}

/** The tree cut down to the matching words (a branch whose own title matches keeps everything under it). */
function filterNode(node: TranslationNode, term: string): TranslationNode | null {
  const titleMatches = term !== '' && !onlySchoolWords.value && (node.labelKey ? t(node.labelKey) : (node.label ?? '')).toLowerCase().includes(term)
  if (titleMatches) return node
  const words = node.words.filter((key) => matchesWord(key, term))
  const children = node.children.map((child) => filterNode(child, term)).filter((child): child is TranslationNode => child !== null)
  return words.length > 0 || children.length > 0 ? { ...node, words, children } : null
}

const filtering = computed(() => search.value.trim() !== '' || onlySchoolWords.value)
const visibleTree = computed(() => {
  if (!filtering.value) return tree
  const term = search.value.trim().toLowerCase()
  return tree.map((node) => filterNode(node, term)).filter((node): node is TranslationNode => node !== null)
})

// --- Load / save -----------------------------------------------------------

onMounted(async () => {
  try {
    overrides.value = await translationOverridesService.get()
    resetDrafts()
  } catch (e) {
    loadError.value = e instanceof ApiRequestError ? e.message : t('admin.translations.loadFailed')
  } finally {
    loading.value = false
  }
})

async function save() {
  saving.value = true
  saveError.value = null
  savedMessage.value = false
  try {
    overrides.value = await translationOverridesService.save(changes.value)
    resetDrafts()
    savedMessage.value = true
  } catch (e) {
    saveError.value = e instanceof ApiRequestError ? e.message : t('admin.translations.saveFailed')
  } finally {
    saving.value = false
  }
}

watch(changes, (next) => {
  if (next.length > 0) savedMessage.value = false
})

onBeforeRouteLeave(async () => changes.value.length === 0 || (await confirmDialog.confirm({ message: t('admin.translations.discardConfirm'), danger: true })))
</script>

<template>
  <div class="pb-24">
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.translations.hint') }}</p>

    <div class="mb-4 flex flex-wrap items-end gap-3">
      <BaseSelect class="w-48" :model-value="locale" :options="localeOptions" :label="t('admin.translations.language')" @update:model-value="changeLocale" />
      <input
        v-model="search"
        type="search"
        :placeholder="t('admin.translations.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
      />
      <label class="flex h-[38px] items-center gap-2 text-sm text-neutral-700">
        <input v-model="onlySchoolWords" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.translations.onlySchoolWords') }}
      </label>
    </div>

    <BaseSpinner v-if="loading" class="mx-auto mt-8" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <template v-else>
      <BaseAlert v-if="saveError" variant="danger" class="mb-4">{{ saveError }}</BaseAlert>

      <p v-if="visibleTree.length === 0" class="rounded-lg border border-neutral-200 p-6 text-center text-sm text-neutral-400">
        {{ t('admin.translations.noResults') }}
      </p>
      <!-- Keyed by language and filter, so switching either redraws the tree
           fresh — a search opens every matching branch, clearing it folds
           them back. -->
      <ul v-else :key="`${locale}|${filtering}`" class="rounded-lg border border-neutral-200 bg-white p-2">
        <TranslationTreeNode
          v-for="node in visibleTree"
          :key="node.id"
          :node="node"
          :locale="locale"
          :drafts="drafts"
          :saved="saved"
          :force-open="filtering"
        />
      </ul>
    </template>

    <!-- Save bar: always reachable, however deep in the tree you are. -->
    <div
      v-if="changes.length > 0 || savedMessage"
      class="fixed inset-x-0 bottom-0 z-10 flex items-center justify-end gap-3 border-t border-neutral-200 bg-white/95 px-4 py-3 backdrop-blur sm:px-6"
      :class="adminUi.sidebarCollapsed ? 'lg:left-16' : 'lg:left-64'"
    >
      <span v-if="savedMessage && changes.length === 0" class="text-sm text-success-600">{{ t('admin.translations.saved') }}</span>
      <template v-else>
        <span class="text-sm text-neutral-600">{{ t('admin.translations.unsavedCount', { count: changes.length }) }}</span>
        <BaseButton variant="outline" :disabled="saving" @click="resetDrafts">{{ t('admin.translations.discard') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </div>
  </div>
</template>
