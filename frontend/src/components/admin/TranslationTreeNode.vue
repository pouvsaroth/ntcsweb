<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { builtInMessage, type Locale } from '@/i18n'
import { placeholders, type TranslationNode } from '@/utils/translationTree'

/**
 * One branch of Settings > Language > Translation's tree (see
 * translationTree.ts): its heading, and when open, a row per word and its
 * sub-branches. Recursive. The drafts themselves live in Translations.vue,
 * shared by every branch, and are edited in place here.
 */
const props = withDefaults(
  defineProps<{
    node: TranslationNode
    locale: Locale
    /** key => what the box holds right now ('' = use the built-in text). */
    drafts: Record<string, string>
    /** key => the school's word as last saved. */
    saved: Record<string, string>
    /** Open regardless of the toggle — a search is showing its matches. */
    forceOpen?: boolean
    depth?: number
  }>(),
  { forceOpen: false, depth: 0 },
)

const { t } = useI18n()

const open = ref(false)
watch(
  () => props.forceOpen,
  (force) => {
    if (force) open.value = true
  },
  { immediate: true },
)

function allWords(node: TranslationNode): string[] {
  return [...node.words, ...node.children.flatMap(allWords)]
}

const words = computed(() => allWords(props.node))
const schoolCount = computed(() => words.value.filter((key) => (props.drafts[key] ?? '') !== '').length)
const changedCount = computed(() => words.value.filter((key) => (props.drafts[key] ?? '') !== (props.saved[key] ?? '')).length)

const title = computed(() => (props.node.labelKey ? t(props.node.labelKey) : props.node.label))

function english(key: string): string {
  return builtInMessage('en', key) ?? ''
}

function builtIn(key: string): string {
  return builtInMessage(props.locale, key) ?? english(key)
}

/** The `{name}` placeholders the built-in text fills in but the school's word dropped. */
function missingPlaceholders(key: string): string[] {
  const draft = props.drafts[key] ?? ''
  if (draft === '') return []
  return placeholders(builtIn(key)).filter((name) => !placeholders(draft).includes(name))
}

function placeholderList(key: string): string {
  return missingPlaceholders(key)
    .map((name) => '{' + name + '}')
    .join(', ')
}

/** Built-in text with a `|` switches between singular and plural — losing it shows both halves. */
function losesPlural(key: string): boolean {
  const draft = props.drafts[key] ?? ''
  return draft !== '' && builtIn(key).includes('|') && !draft.includes('|')
}
</script>

<template>
  <li>
    <button
      type="button"
      class="flex w-full items-center gap-2 rounded-lg py-2 pr-2 text-left hover:bg-neutral-50"
      :style="{ paddingLeft: `${depth * 1.25 + 0.25}rem` }"
      :aria-expanded="open"
      @click="open = !open"
    >
      <svg class="h-3.5 w-3.5 shrink-0 text-neutral-400 transition-transform" :class="open ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
      </svg>
      <span class="min-w-0 truncate text-sm" :class="depth === 0 ? 'font-semibold text-neutral-900' : 'font-medium text-neutral-800'">{{ title }}</span>
      <span v-if="changedCount > 0" class="shrink-0 rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-800">
        {{ t('admin.translations.unsavedCount', { count: changedCount }) }}
      </span>
      <span class="ml-auto shrink-0 text-xs text-neutral-400">
        <template v-if="schoolCount > 0">{{ t('admin.translations.schoolCount', { count: schoolCount }) }} · </template>{{ t('admin.translations.wordCount', { count: words.length }) }}
      </span>
    </button>

    <template v-if="open">
      <div v-if="node.words.length > 0" class="mb-2 space-y-2" :style="{ paddingLeft: `${depth * 1.25 + 1.5}rem` }">
        <div
          v-for="key in node.words"
          :key="key"
          class="grid gap-2 rounded-lg border p-2.5 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"
          :class="(drafts[key] ?? '') !== (saved[key] ?? '') ? 'border-amber-300 bg-amber-50/50' : 'border-neutral-200 bg-white'"
        >
          <div class="min-w-0">
            <p class="break-words text-sm text-neutral-800">{{ builtIn(key) }}</p>
            <p v-if="locale !== 'en'" class="break-words text-xs text-neutral-500">{{ english(key) }}</p>
            <p class="mt-0.5 break-all font-mono text-[11px] text-neutral-400">{{ key }}</p>
          </div>
          <div class="min-w-0">
            <div class="flex items-start gap-2">
              <textarea
                v-model="drafts[key]"
                rows="1"
                :placeholder="t('admin.translations.useBuiltIn')"
                class="block min-h-[38px] w-full resize-y rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
              />
              <button
                v-if="(drafts[key] ?? '') !== ''"
                type="button"
                class="mt-2 shrink-0 text-xs font-medium text-neutral-500 hover:text-danger-600"
                :title="t('admin.translations.resetHint')"
                @click="drafts[key] = ''"
              >
                {{ t('admin.translations.reset') }}
              </button>
            </div>
            <p v-if="missingPlaceholders(key).length > 0" class="mt-1 text-xs text-amber-700">
              {{ t('admin.translations.keepPlaceholders', { names: placeholderList(key) }) }}
            </p>
            <p v-if="losesPlural(key)" class="mt-1 text-xs text-amber-700">{{ t('admin.translations.keepPlural') }}</p>
          </div>
        </div>
      </div>

      <ul v-if="node.children.length > 0">
        <TranslationTreeNode
          v-for="child in node.children"
          :key="child.id"
          :node="child"
          :locale="locale"
          :drafts="drafts"
          :saved="saved"
          :force-open="forceOpen"
          :depth="depth + 1"
        />
      </ul>
    </template>
  </li>
</template>
