<script setup lang="ts">
import type { Block, Field, MediaFile, RecordRef } from '~/types/api'

// Input for one field, chosen by its type. Values are sent as entered - the API converts them
// (e.g. "12,50", "29.10.2025") with the same rules as the import.
const props = defineProps<{ field: Field, entity?: string, reference?: RecordRef | RecordRef[] | null, disabled?: boolean }>()
const model = defineModel<unknown>()

const text = computed<string>({
  get: () => model.value === null || model.value === undefined ? '' : String(model.value),
  set: (value) => { model.value = value === '' ? null : value }
})

// <input type="datetime-local"> uses "2025-10-29T18:00", the API "2025-10-29 18:00:00"
const dateTime = computed({
  get: () => typeof model.value === 'string' ? model.value.slice(0, 16).replace(' ', 'T') : '',
  set: (value: string) => { model.value = value ? `${value.replace('T', ' ')}:00`.slice(0, 19) : null }
})

const step = computed(() => props.field.type === 'decimal' ? 1 / 10 ** (props.field.scale ?? 2) : 1)
// Numbers with a range as slider: empty until moved (then the value next to it, with a button to empty it)
const hasSlider = computed(() => !!props.field.slider && props.field.min_value != null && props.field.max_value != null)
const sliderValue = computed<number>({
  get: () => model.value === null || model.value === undefined || model.value === '' ? Number(props.field.min_value) : Number(model.value),
  set: (value) => { model.value = value }
})

// Enum: the values of the field; "-" = empty (a select needs a value for it)
const NONE = '-'
const enumItems = computed(() => (props.field.options ?? []).map(o => ({ value: o.value, label: o.label })))
// Date range: {from, to} as two date inputs
const range = computed(() => (model.value && typeof model.value === 'object' && !Array.isArray(model.value) ? model.value : {}) as { from?: string | null, to?: string | null })
function setRange(part: 'from' | 'to', value: string) {
  const next = { from: range.value.from ?? null, to: range.value.to ?? null, [part]: value || null }
  model.value = next.from || next.to ? next : null
}

// JSON: edited as text, sent as text (the API checks and stores it)
const jsonText = computed<string>({
  get: () => model.value === null || model.value === undefined ? '' : typeof model.value === 'string' ? model.value : JSON.stringify(model.value, null, 2),
  set: (value) => { model.value = value.trim() === '' ? null : value }
})
const jsonError = computed(() => {
  if (typeof model.value !== 'string' || model.value.trim() === '') return false
  try {
    JSON.parse(model.value)
    return false
  } catch {
    return true
  }
})

// Color: the picker needs #rrggbb
const colorPick = computed(() => typeof model.value === 'string' && /^#[0-9a-f]{6}/i.test(model.value) ? model.value.slice(0, 7) : '#000000')

const enumValue = computed<string>({
  get: () => typeof model.value === 'string' ? model.value : NONE,
  set: (value) => { model.value = value === NONE ? null : value }
})
</script>

<template>
  <USelectMenu
    v-if="field.type === 'enum' && field.repeatable"
    :model-value="(model as string[] | null) ?? []"
    :items="enumItems"
    value-key="value"
    multiple
    :disabled="disabled"
    class="w-full"
    @update:model-value="model = $event.length ? $event : null"
  />
  <USelect
    v-else-if="field.type === 'enum'"
    v-model="enumValue"
    :items="[{ value: NONE, label: '–' }, ...enumItems]"
    :disabled="disabled"
    class="w-full"
  />
  <div v-else-if="field.type === 'daterange'" class="flex flex-wrap items-center gap-2">
    <UInput :model-value="range.from ?? ''" type="date" :disabled="disabled" @update:model-value="setRange('from', String($event))" />
    <span class="text-muted">–</span>
    <UInput :model-value="range.to ?? ''" type="date" :disabled="disabled" @update:model-value="setRange('to', String($event))" />
  </div>
  <CodeInput v-else-if="field.type === 'code'" v-model="(model as any)" :disabled="disabled" />
  <div v-else-if="field.type === 'json'" class="space-y-1">
    <UTextarea v-model="jsonText" autoresize :rows="4" class="font-mono w-full" :color="jsonError ? 'error' : undefined" :highlight="jsonError" :disabled="disabled" />
    <p v-if="jsonError" class="text-sm text-error">{{ $t('input.invalidJson') }}</p>
  </div>
  <div v-else-if="field.type === 'color' && !field.repeatable" class="flex items-center gap-2">
    <input type="color" :value="colorPick" :disabled="disabled" class="size-9 shrink-0 cursor-pointer rounded border border-default bg-transparent p-0.5" :aria-label="field.label" @input="text = ($event.target as HTMLInputElement).value">
    <UInput v-model="text" placeholder="#1e40af" maxlength="9" class="font-mono w-32" :disabled="disabled" />
  </div>
  <UInput v-else-if="field.type === 'phone' && !field.repeatable" v-model="text" type="tel" placeholder="+49 30 123456" :disabled="disabled" />
  <PluginFieldInput v-else-if="field.type.includes('.')" v-model="model" :field="field" :disabled="disabled" />
  <BlocksInput v-else-if="field.type === 'group' && field.blocks?.length" v-model="model as Block[] | null" :field="field" :entity="entity" :disabled="disabled" />
  <RepeatableInput
    v-else-if="field.repeatable && field.type !== 'media'"
    v-model="model as unknown[] | null"
    :field="field"
    :entity="entity"
    :references="Array.isArray(reference) ? reference : null"
    :disabled="disabled"
  />
  <GroupInput v-else-if="field.type === 'group'" v-model="model as Record<string, unknown> | null" :field="field" :entity="entity" :disabled="disabled" />
  <MarkdownEditor v-else-if="field.type === 'markdown'" v-model="model as string | null" :disabled="disabled" />
  <UInput
    v-else-if="field.type === 'slug'"
    v-model="text"
    :placeholder="field.slug_source ? $t('input.generated') : $t('input.slugExample')"
    :maxlength="field.length ?? undefined"
    :disabled="disabled"
    class="font-mono"
    @blur="text = slugify(text, field.length ?? 255)"
  />
  <div v-else-if="field.type === 'regex'" class="space-y-1">
    <UInput v-model="text" :maxlength="field.length ?? undefined" :disabled="disabled" :color="matchesPattern(field.pattern, text) === false ? 'error' : undefined" :highlight="matchesPattern(field.pattern, text) === false" class="font-mono w-full" />
    <p v-if="matchesPattern(field.pattern, text) === false" class="text-sm text-error">{{ field.pattern_message || $t('input.noMatch', { pattern: field.pattern ?? '' }) }}</p>
  </div>
  <UTextarea v-else-if="field.type === 'text'" v-model="text" autoresize :rows="3" :disabled="disabled" />
  <USwitch v-else-if="field.type === 'boolean'" :model-value="!!model" :disabled="disabled" @update:model-value="model = $event" />
  <MediaInput v-else-if="field.type === 'media'" v-model="model as MediaFile | MediaFile[] | string | null" :field="field" :entity="entity" :disabled="disabled" />
  <UInput v-else-if="field.type === 'autoincrement'" :model-value="text" :placeholder="$t('input.assigned')" class="font-mono" disabled />
  <UInput v-else-if="field.type === 'uuid'" v-model="text" :placeholder="$t('input.uuid', { version: field.uuid_version ?? 7 })" class="font-mono" maxlength="38" :disabled="disabled" />
  <div v-else-if="(field.type === 'integer' || field.type === 'decimal') && hasSlider" class="flex items-center gap-3">
    <USlider v-model="sliderValue" :min="Number(field.min_value)" :max="Number(field.max_value)" :step="step" :disabled="disabled" class="flex-1" :class="{ 'opacity-50': model === null || model === undefined || model === '' }" />
    <span class="w-14 text-right font-mono text-sm tabular-nums">{{ model === null || model === undefined || model === '' ? '–' : model }}</span>
    <UButton v-if="!disabled && model !== null && model !== undefined && model !== ''" icon="i-lucide-x" size="xs" color="neutral" variant="ghost" :aria-label="$t('common.remove')" @click="model = null" />
  </div>
  <UInput v-else-if="field.type === 'integer' || field.type === 'decimal' || field.type === 'order'" v-model="text" type="number" :step="step" :min="field.min_value ?? undefined" :max="field.max_value ?? undefined" :disabled="disabled" />
  <UInput v-else-if="field.type === 'date'" v-model="text" type="date" :disabled="disabled" />
  <UInput v-else-if="field.type === 'datetime'" v-model="dateTime" type="datetime-local" step="1" :disabled="disabled" />
  <UInput v-else-if="field.type === 'time'" v-model="text" type="time" step="1" :disabled="disabled" />
  <ReferenceSelect v-else-if="field.type === 'reference' && field.reference" v-model="model as string | null" :entity="field.reference" :initial="Array.isArray(reference) ? null : reference" :disabled="disabled" />
  <UInput
    v-else
    v-model="text"
    :type="field.type === 'email' ? 'email' : field.type === 'url' ? 'url' : 'text'"
    :maxlength="field.length ?? undefined"
    :disabled="disabled"
  />
</template>
