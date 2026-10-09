<script setup lang="ts">
import type { Field, RecordRef } from '~/types/api'

// Repeatable fields (not media - MediaInput lists files itself): one input per value, add and
// remove, and - if the field is sortable - drag & drop at the handle (or its arrow keys) for the order.
const props = defineProps<{ field: Field, entity?: string, references?: RecordRef[] | null, disabled?: boolean }>()
const model = defineModel<unknown[] | null>()

const items = computed<unknown[]>(() => Array.isArray(model.value) ? model.value : [])
// Every value is edited like a single value of the field
const single = computed<Field>(() => ({ ...props.field, repeatable: false, required: false }))
const sortable = computed(() => props.field.sortable !== false && !props.disabled && items.value.length > 1)
const canAdd = computed(() => props.field.repeat_max == null || items.value.length < props.field.repeat_max)
const { t } = useI18n()
const hint = computed(() => {
  const { repeat_min: min, repeat_max: max } = props.field
  if (min && max) return t('repeat.range', { min, max })
  if (max) return t('repeat.atMost', max)
  if (min) return t('repeat.atLeast', min)
  return ''
})
// Labels of references stay with their value when the order changes
const refs = ref<(RecordRef | null)[]>([...(props.references ?? [])])

function set(list: unknown[]) {
  model.value = list.length ? list : null
}
function update(index: number, value: unknown) {
  set(items.value.map((item, i) => i === index ? value : item))
}
function add() {
  set([...items.value, props.field.type === 'boolean' ? false : null])
  refs.value.push(null)
}
function remove(index: number) {
  set(items.value.filter((_, i) => i !== index))
  refs.value.splice(index, 1)
}
function move(from: number, to: number) {
  if (to < 0 || to >= items.value.length || from === to) return
  const list = [...items.value]
  const [item] = list.splice(from, 1)
  list.splice(to, 0, item)
  const [ref] = refs.value.splice(from, 1)
  refs.value.splice(to, 0, ref ?? null)
  set(list)
}

const drag = useDragSort(move)
</script>

<template>
  <div class="space-y-2">
    <ul v-if="items.length" class="space-y-2">
      <li
        v-for="(item, index) in items"
        :key="index"
        class="flex items-start gap-2 rounded-md border border-default bg-default p-2 transition-colors"
        :class="drag.rowClass(index)"
        v-bind="drag.row(index, sortable)"
      >
        <DragHandle v-if="sortable" class="mt-1" @move="by => drag.move(index, index + by, items.length)" />
        <div class="min-w-0 flex-1">
          <FieldInput :model-value="item" :field="single" :entity="entity" :reference="refs[index]" :disabled="disabled" @update:model-value="update(index, $event)" />
        </div>
        <div v-if="!disabled" class="flex shrink-0 items-center">
          <UButton icon="i-lucide-x" color="neutral" variant="ghost" size="sm" :aria-label="$t('repeat.remove')" @click="remove(index)" />
        </div>
      </li>
    </ul>
    <div v-if="!disabled" class="flex flex-wrap items-center gap-3">
      <UButton v-if="canAdd" icon="i-lucide-plus" color="neutral" variant="outline" size="sm" :label="$t('repeat.add')" @click="add" />
      <span v-if="hint" class="text-sm text-muted">{{ $t('repeat.count', items.length) }} · {{ $t('repeat.allowed', { hint }) }}</span>
    </div>
  </div>
</template>
