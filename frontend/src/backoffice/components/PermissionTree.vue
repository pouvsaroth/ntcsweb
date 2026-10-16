<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import type { PermissionNode } from '../api'

/**
 * The permission tree editor: a group's box ticks or clears every key under
 * it, and shows "some" (indeterminate) when only part of it is held. Only
 * leaf keys are ever stored.
 */
const props = defineProps<{ tree: PermissionNode[]; modelValue: string[]; disabled?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [keys: string[]] }>()

const { locale } = useI18n()

function leaves(node: PermissionNode): string[] {
  return node.children.length ? node.children.flatMap(leaves) : [node.key]
}

function label(node: PermissionNode): string {
  return locale.value === 'km' && node.label_km ? node.label_km : node.label
}

function state(node: PermissionNode): 'all' | 'some' | 'none' {
  const keys = leaves(node)
  const held = keys.filter((k) => props.modelValue.includes(k)).length
  return held === 0 ? 'none' : held === keys.length ? 'all' : 'some'
}

function toggle(node: PermissionNode, on: boolean): void {
  const keys = leaves(node)
  const rest = props.modelValue.filter((k) => !keys.includes(k))
  emit('update:modelValue', on ? [...rest, ...keys] : rest)
}
</script>

<template>
  <ul class="space-y-3">
    <li v-for="group in tree" :key="group.key" class="rounded-lg border border-neutral-200 p-3">
      <label class="flex items-center gap-2 text-sm font-semibold text-neutral-900">
        <input
          type="checkbox"
          class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
          :checked="state(group) === 'all'"
          :indeterminate="state(group) === 'some'"
          :disabled="disabled"
          @change="toggle(group, ($event.target as HTMLInputElement).checked)"
        />
        {{ label(group) }}
      </label>
      <ul v-if="group.children.length" class="mt-2 space-y-1.5 pl-6">
        <li v-for="leaf in group.children" :key="leaf.key">
          <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input
              type="checkbox"
              class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              :checked="state(leaf) === 'all'"
              :disabled="disabled"
              @change="toggle(leaf, ($event.target as HTMLInputElement).checked)"
            />
            {{ label(leaf) }}
            <code class="text-xs text-neutral-400">{{ leaf.key }}</code>
          </label>
        </li>
      </ul>
    </li>
  </ul>
</template>
