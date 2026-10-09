<script setup lang="ts">
import type { ComputedRef } from 'vue'
import type { Block, Field, FieldGroupDef } from '~/types/api'

// Blocks (page builder): a list of items, each one of the field groups the field offers. Added
// from the menu (at the end, or by the "+" that shows between two blocks on hover), moved by drag & drop or the arrows, duplicated,
// collapsed. Every block keeps its _key - the website can use it as key, the preview to find it.
const props = defineProps<{ field: Field, entity?: string, disabled?: boolean }>()
const model = defineModel<Block[] | null>()
const { t } = useI18n()

const blocks = computed<Block[]>(() => Array.isArray(model.value) ? model.value : [])
const allTypes = computed<FieldGroupDef[]>(() => props.field.blocks ?? [])
// A list taking every block ("*" - the columns of Columns) offers what the list around it offers: in a form
// the fields of the form, on a page the blocks of the page
const offeredAround = inject<ComputedRef<string[]> | null>('excellent-blocks-offered', null)
const takesEverything = computed(() => (props.field.block_categories ?? []).includes('*'))
const types = computed<FieldGroupDef[]>(() => takesEverything.value && offeredAround?.value
  ? allTypes.value.filter(type => offeredAround.value.includes(type.name))
  : allTypes.value)
provide('excellent-blocks-offered', computed(() => types.value.map(type => type.name)))
const typeOf = (block: Block) => allTypes.value.find(type => type.name === block._type)
// GroupInput edits one object of a group: the field with the group of the block
const groupField = (block: Block): Field => ({ ...props.field, repeatable: false, required: false, blocks: null, group: typeOf(block) ?? null })
const canAdd = computed(() => !props.disabled && (props.field.repeat_max == null || blocks.value.length < props.field.repeat_max))
const sortable = computed(() => !props.disabled && blocks.value.length > 1)

const newKey = () => Array.from(crypto.getRandomValues(new Uint8Array(6)), byte => byte.toString(16).padStart(2, '0')).join('')
const collapsed = ref<Record<string, boolean>>({})
// Long pages start collapsed
onMounted(() => {
  if (blocks.value.length > 3) blocks.value.forEach((block) => { collapsed.value[block._key] = true })
})

function set(list: Block[]) {
  model.value = list.length ? list : null
}
function update(index: number, value: Record<string, unknown> | null | undefined) {
  const block = blocks.value[index]!
  set(blocks.value.map((item, i) => i === index ? { ...(value ?? {}), _type: block._type, _key: block._key } : item))
}
function add(type: FieldGroupDef, at = blocks.value.length) {
  const block: Block = { _type: type.name, _key: newKey() }
  for (const sub of type.fields) block[sub.name] = sub.type === 'boolean' ? false : null
  const list = [...blocks.value]
  list.splice(at, 0, block)
  set(list)
}
// A copy gets new keys - the blocks nested in it too
function rekey(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(rekey)
  if (!value || typeof value !== 'object') return value
  const copy = Object.fromEntries(Object.entries(value).map(([key, inner]) => [key, rekey(inner)]))
  return '_key' in copy && '_type' in copy ? { ...copy, _key: newKey() } : copy
}
function duplicate(index: number) {
  const list = [...blocks.value]
  list.splice(index + 1, 0, rekey(JSON.parse(JSON.stringify(blocks.value[index]!))) as Block)
  set(list)
}
function remove(index: number) {
  set(blocks.value.filter((_, i) => i !== index))
}
function move(from: number, to: number) {
  if (to < 0 || to >= blocks.value.length || from === to) return
  const list = [...blocks.value]
  const [block] = list.splice(from, 1)
  list.splice(to, 0, block!)
  set(list)
}
function toggleAll(state: boolean) {
  blocks.value.forEach((block) => { collapsed.value[block._key] = state })
}

const blockMenu = (index: number) => [
  [{ label: t('blocks.duplicate'), icon: 'i-lucide-copy', onSelect: () => duplicate(index) }],
  [{ label: t('common.delete'), icon: 'i-lucide-trash-2', color: 'error' as const, onSelect: () => remove(index) }],
]

// Collapsed blocks show the first text of the block
function summary(block: Block): string {
  for (const sub of typeOf(block)?.fields ?? []) {
    const value = block[sub.name]
    // Code: the file name, or the first line of the code
    if (sub.type === 'code' && value && typeof value === 'object') {
      const code = value as { file?: string | null, code?: string }
      const text = code.file || (code.code ?? '').trim().split('\n')[0] || ''
      if (text) return text.length > 70 ? `${text.slice(0, 70)}…` : text
      continue
    }
    if (typeof value === 'string' && value.trim() && ['string', 'text', 'markdown', 'url', 'email', 'slug'].includes(sub.type)) {
      const text = value.replace(/[#*_>`[\]()!-]+/g, ' ').replace(/\s+/g, ' ').trim()
      return text.length > 70 ? `${text.slice(0, 70)}…` : text
    }
  }
  return ''
}

const drag = useDragSort(move)
</script>

<template>
  <div class="space-y-2">
    <div v-if="blocks.length > 1" class="flex justify-end gap-1">
      <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-chevrons-down-up" :label="$t('blocks.collapseAll')" @click="toggleAll(true)" />
      <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-chevrons-up-down" :label="$t('blocks.expandAll')" @click="toggleAll(false)" />
    </div>
    <ul v-if="blocks.length">
      <template v-for="(block, index) in blocks" :key="block._key">
        <!-- Between two blocks (and before the first): "+" on hover inserts a block there -->
        <li class="group/gap relative h-3" :class="index === 0 && !canAdd && 'hidden'">
          <div v-if="canAdd && !disabled" class="absolute inset-x-0 top-1/2 z-10 flex -translate-y-1/2 items-center opacity-0 transition-opacity group-hover/gap:opacity-100 focus-within:opacity-100 has-[[data-state=open]]:opacity-100">
            <span class="h-0.5 flex-1 rounded bg-primary/60" />
            <BlockPicker :types="types" @pick="type => add(type, index)">
              <UButton icon="i-lucide-plus" size="xs" class="mx-1 rounded-full" :aria-label="$t('blocks.insertHere')" :title="$t('blocks.insertHere')" />
            </BlockPicker>
            <span class="h-0.5 flex-1 rounded bg-primary/60" />
          </div>
        </li>
        <li
          class="rounded-md border border-default bg-default transition-colors"
          :class="drag.rowClass(index)"
          :data-block-key="block._key"
          v-bind="drag.row(index, sortable)"
        >
          <div class="flex items-center gap-2 px-2 py-1.5" :class="!collapsed[block._key] && 'border-b border-default'">
            <DragHandle v-if="sortable" @move="by => drag.move(index, index + by, blocks.length)" />
            <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-start" @click="collapsed[block._key] = !collapsed[block._key]">
              <UIcon :name="collapsed[block._key] ? 'i-lucide-chevron-right' : 'i-lucide-chevron-down'" class="size-4 shrink-0 text-muted" />
              <UBadge :label="typeOf(block)?.label ?? block._type" :color="typeOf(block) ? 'primary' : 'error'" variant="subtle" size="sm" />
              <span v-if="collapsed[block._key]" class="truncate text-sm text-muted">{{ summary(block) }}</span>
            </button>
            <div v-if="!disabled" class="flex shrink-0 items-center">
              <UDropdownMenu :items="blockMenu(index)">
                <UButton icon="i-lucide-ellipsis-vertical" color="neutral" variant="ghost" size="xs" :aria-label="$t('blocks.actions')" />
              </UDropdownMenu>
            </div>
          </div>
          <div v-show="!collapsed[block._key]" class="p-2">
            <GroupInput v-if="typeOf(block)" :model-value="block" :field="groupField(block)" :entity="entity" :disabled="disabled" @update:model-value="update(index, $event)" />
            <p v-else class="text-sm text-error">{{ $t('blocks.unknownType', { type: block._type }) }}</p>
          </div>
        </li>
      </template>
    </ul>
    <p v-else class="rounded-md border border-dashed border-default p-4 text-center text-sm text-muted">{{ $t('blocks.empty') }}</p>
    <div v-if="canAdd" class="flex flex-wrap items-center gap-3">
      <BlockPicker :types="types" @pick="type => add(type)">
        <UButton icon="i-lucide-plus" color="neutral" variant="outline" size="sm" :label="$t('blocks.add')" trailing-icon="i-lucide-chevron-down" />
      </BlockPicker>
      <span class="text-sm text-muted">{{ $t('blocks.count', blocks.length) }}</span>
    </div>
  </div>
</template>
