<script setup lang="ts">
import type { AccessKey, Entity, FieldTypeKey, ImportAnalysis, ImportResult, ReferenceCandidate } from '~/types/api'

// CSV/Excel import: 1. upload, 2. map columns to fields (new entity or existing one), 3. preview, 4. done.
const { t } = useI18n()
useHead({ title: () => t('nav.import') })
const route = useRoute()
const { isAdmin, canImport, can, loadSession } = useAuth()
if (!canImport.value) await navigateTo('/')

interface ColumnState {
  column: string
  samples: string[]
  empty: number
  /** Mode "new": the column becomes a field */
  include: boolean
  /** Field name of a new field (mode "new", or target "__new" in mode "existing") */
  field: string
  /** Mode "existing": the field the column goes into, "__new" or "-" (ignore) */
  target: string
  label: string
  type: FieldTypeKey
  length: number | null
  scale: number | null
  uuid_version: number | null
  required: boolean
  unique: boolean
  reference: string | null
  match: string | null
  /** Reference: values that are not found are added to the target */
  create_missing: boolean
  /** Reference to a new entity made from the column's values (reference = NEW_ENTITY) */
  new_slug: string
  new_name: string
  /** Several values per cell ("a | b") */
  repeatable: boolean
  /** Entities the values could point to */
  candidates: ReferenceCandidate[]
  distinct: number
  /** Yes/no: the different values of the column, which of them mean yes / no, empty cells = no */
  values: string[]
  true_values: string[]
  false_values: string[]
  empty_false: boolean
}

const NONE = '-'
const NEW_FIELD = '__new'
const NEW_ENTITY = '__new_entity'
const step = ref(1)
const analysis = ref<ImportAnalysis | null>(null)
const preview = ref<ImportResult | null>(null)
const result = ref<ImportResult | null>(null)
const fileInput = ref<HTMLInputElement>()
const dragging = ref(false)
const { submit, saving, errors } = useSubmit()

const { data: entityData } = await useAsyncData('import-entities', () => useApi()<{ data: Entity[] }>('/entities'))
const entities = computed(() => entityData.value?.data ?? [])
const importable = computed(() => entities.value.filter(e => can('import', e.slug)))

const mode = ref<'new' | 'existing'>(isAdmin.value ? 'new' : 'existing')
const target = ref<string>(String(route.query.target ?? '') || NONE)
const key = ref<string>(NONE)
const entityForm = reactive({ slug: '', name: '', access: 'public' as AccessKey, label_field: NONE })
const sheet = ref<string | null>(null)
const delimiter = ref<string | null>(null)
const columns = ref<ColumnState[]>([])

// Files are uploaded in the record, not imported
const typeItems = computed(() => FIELD_TYPES.filter((value): boolean => value !== 'media').map(value => ({ value, label: t(`fieldTypes.${value}`), icon: FIELD_TYPE_ICONS[value] })))
const delimiterItems = computed(() => [{ value: ';', label: t('import.semicolon') }, { value: ',', label: t('import.comma') }, { value: '\t', label: t('import.tab') }, { value: '|', label: t('import.pipe') }])
const targetEntity = computed(() => entities.value.find(e => e.slug === target.value))

function upload(file: File | undefined) {
  if (!file) return
  const body = new FormData()
  body.append('file', file)
  return submit(() => useApi()<{ data: ImportAnalysis }>('/imports', { method: 'POST', body }), '').then((res) => {
    if (res) applyAnalysis(res.data, true)
  })
}

function onDrop(event: DragEvent) {
  dragging.value = false
  upload(event.dataTransfer?.files?.[0])
}

function applyAnalysis(data: ImportAnalysis, initial: boolean) {
  analysis.value = data
  sheet.value = data.sheet
  delimiter.value = data.delimiter
  columns.value = data.columns.map(c => ({
    column: c.column,
    samples: c.samples,
    empty: c.empty,
    include: true,
    field: c.suggestion.field,
    target: NONE,
    label: c.suggestion.label,
    type: c.suggestion.type,
    length: c.suggestion.length,
    scale: c.suggestion.scale,
    uuid_version: c.suggestion.uuid_version,
    required: c.suggestion.required,
    unique: c.suggestion.unique,
    reference: c.suggestion.reference,
    match: c.suggestion.match,
    create_missing: false,
    new_slug: c.suggestion.field.endsWith('s') ? c.suggestion.field : `${c.suggestion.field}s`,
    new_name: c.suggestion.label,
    repeatable: false,
    candidates: c.references ?? [],
    distinct: c.distinct,
    values: c.values ?? [],
    true_values: [...(c.suggestion.true_values ?? [])],
    false_values: [...(c.suggestion.false_values ?? [])],
    empty_false: c.suggestion.empty_false ?? false
  }))
  if (initial) {
    Object.assign(entityForm, { slug: data.entity.slug, name: data.entity.name, access: data.entity.access, label_field: data.entity.label_field ?? NONE })
    // The file fits an existing entity well (or the user came from one): import into it
    const best = data.existing[0]
    if (target.value === NONE && best && best.score >= 0.5) target.value = best.entity
    if (target.value !== NONE || !data.may_create) mode.value = 'existing'
  }
  applyExistingMapping()
  step.value = 2
}

// Existing entity: map columns to its fields by the suggestion of the server (name/label match)
function applyExistingMapping() {
  if (!analysis.value) return
  const match = analysis.value.existing.find(m => m.entity === target.value)
  for (const column of columns.value) {
    column.target = match?.columns[column.column] ?? NONE
  }
  key.value = match?.key ?? NONE
}
watch(target, applyExistingMapping)

async function reanalyze() {
  if (!analysis.value) return
  const res = await submit(() => useApi()<{ data: ImportAnalysis }>(`/imports/${analysis.value!.import_id}/analyze`, { method: 'POST', body: { sheet: sheet.value, delimiter: delimiter.value } }), '')
  if (res) applyAnalysis(res.data, false)
}

function matchItems(slug: string | null) {
  const entity = entities.value.find(e => e.slug === slug)
  return [{ value: 'id', label: 'ID' }, ...(entity?.fields ?? []).filter(f => f.type !== 'reference' && f.type !== 'boolean').map(f => ({ value: f.name, label: f.unique ? t('import.uniqueField', { field: f.label }) : f.label }))]
}
const referenceItems = computed(() => [
  ...(isAdmin.value ? [{ value: NEW_ENTITY, label: t('import.newEntityFromValues'), icon: 'i-lucide-sparkles' }] : []),
  ...entities.value.map(e => ({ value: e.slug, label: e.name }))
])

// Reference columns: the column points to records of another entity - an existing one (found by
// the match field, missing values can be added) or a new one made from the column's values
function isReference(c: ColumnState) {
  if (mode.value === 'new') return c.include && c.type === 'reference'
  return c.target === NEW_FIELD ? c.type === 'reference' : existingField(c)?.type === 'reference'
}
// Values that repeat (or lists): good candidates for an entity of their own
const repeats = (c: ColumnState) => c.distinct > 0 && analysis.value !== null && c.distinct < analysis.value.row_count - c.empty
function linkTo(c: ColumnState, candidate: ReferenceCandidate | null) {
  if (mode.value === 'existing') {
    // An existing reference field to that entity, otherwise a new one
    const field = candidate && targetEntity.value?.fields.find(f => f.type === 'reference' && f.reference === candidate.entity && !columns.value.some(o => o !== c && o.target === f.name))
    if (field) {
      c.target = field.name
      c.match = candidate!.match
      return
    }
    c.target = NEW_FIELD
  }
  c.include = true
  c.repeatable = c.samples.some(sample => sample.includes('|'))
  c.type = 'reference'
  c.reference = candidate ? candidate.entity : NEW_ENTITY
  c.match = candidate ? candidate.match : 'name'
  c.unique = false
}
// Linking adds a field (or an entity): admins only
const offersLink = (c: ColumnState) => isAdmin.value && !isReference(c) && (c.candidates.length > 0 || repeats(c))
const targetFieldItems = computed(() => [
  { value: NONE, label: t('import.skip') },
  ...(targetEntity.value?.fields ?? []).filter(f => f.type !== 'media').map(f => ({ value: f.name, label: `${f.label} (${t(`fieldTypes.${f.type}`)})` })),
  ...(isAdmin.value ? [{ value: NEW_FIELD, label: t('import.newField') }] : [])
])
const keyItems = computed(() => [
  { value: NONE, label: t('import.onlyNew') },
  ...(targetEntity.value?.fields ?? []).filter(f => columns.value.some(c => c.target === f.name)).map(f => ({ value: f.name, label: t('import.updateBy', { field: f.label }) }))
])
const labelFieldItems = computed(() => [{ value: NONE, label: t('schema.automatic') }, ...columns.value.filter(c => c.include).map(c => ({ value: c.field, label: c.label || c.field }))])

function definition(c: ColumnState) {
  return {
    label: c.label,
    type: c.type,
    length: c.type === 'string' || c.type === 'slug' ? c.length : null,
    scale: c.type === 'decimal' ? c.scale : null,
    uuid_version: c.type === 'uuid' ? (c.uuid_version ?? 7) : null,
    required: isGeneratedType(c.type) ? false : c.required,
    unique: isGeneratedType(c.type) || c.unique,
    ...referencePlan(c)
  }
}

function referencePlan(c: ColumnState) {
  if (c.type !== 'reference') return { reference: null, match: null }
  if (c.reference === NEW_ENTITY) return { reference: null, match: null, repeatable: c.repeatable, new_reference: { slug: c.new_slug, name: c.new_name } }
  return { reference: c.reference, match: c.match, repeatable: c.repeatable, create_missing: c.create_missing && c.match !== 'id' }
}

const entityName = (slug: string | null | undefined) => entities.value.find(e => e.slug === slug)?.name ?? slug ?? ''

function existingField(c: ColumnState) {
  return targetEntity.value?.fields.find(f => f.name === c.target)
}

// Yes/no columns (new field or existing one): which values mean yes and no. Empty lists = the usual
// words (ja/nein, yes/no, 1/0, true/false ...)
function isBoolean(c: ColumnState) {
  if (mode.value === 'new') return c.include && c.type === 'boolean'
  return c.target === NEW_FIELD ? c.type === 'boolean' : existingField(c)?.type === 'boolean'
}
const booleanPlan = (c: ColumnState) => isBoolean(c) ? { true_values: c.true_values, false_values: c.false_values, empty_false: c.empty_false } : {}

const plan = computed(() => ({
  sheet: sheet.value,
  delimiter: delimiter.value,
  mode: mode.value,
  ...(mode.value === 'new'
    ? {
        entity: { ...entityForm, label_field: entityForm.label_field === NONE ? null : entityForm.label_field },
        columns: columns.value.map(c => c.include ? { column: c.column, field: c.field, ...definition(c), ...booleanPlan(c) } : { column: c.column, field: null })
      }
    : {
        target: target.value,
        key: key.value === NONE ? null : key.value,
        columns: columns.value.map((c) => {
          if (c.target === NONE) return { column: c.column, field: null }
          if (c.target === NEW_FIELD) return { column: c.column, field: c.field, new: true, ...definition(c), ...booleanPlan(c) }
          const reference = existingField(c)?.type === 'reference'
          return { column: c.column, field: c.target, match: reference ? (c.match ?? 'id') : null, create_missing: reference && c.create_missing && (c.match ?? 'id') !== 'id', ...booleanPlan(c) }
        })
      })
}))

const error = (column: string, prop: string) => errors.value[`columns.${column}.${prop}`]
const isImported = (c: ColumnState) => mode.value === 'new' ? c.include : c.target !== NONE
const importedCount = computed(() => columns.value.filter(isImported).length)

async function loadPreview() {
  const res = await submit(() => useApi()<{ data: ImportResult }>(`/imports/${analysis.value!.import_id}/preview`, { method: 'POST', body: plan.value }), '')
  if (res) {
    preview.value = res.data
    step.value = 3
  }
}

async function run() {
  const res = await submit(() => useApi()<{ data: ImportResult }>(`/imports/${analysis.value!.import_id}/run`, { method: 'POST', body: plan.value }), t('import.finished'))
  if (res) {
    result.value = res.data
    step.value = 4
    await loadSession()
    clearNuxtData()
  }
}

function restart() {
  if (analysis.value && step.value < 4) useApi()(`/imports/${analysis.value.import_id}`, { method: 'DELETE' }).catch(() => {})
  analysis.value = null
  preview.value = null
  result.value = null
  step.value = 1
}

const resultSlug = computed(() => result.value?.entity ?? (mode.value === 'new' ? entityForm.slug : target.value))
const steps = computed(() => [{ title: t('import.stepFile') }, { title: t('import.stepMapping') }, { title: t('import.stepPreview') }, { title: t('import.stepDone') }])
</script>

<template>
  <div class="space-y-6">
    <AppPageHeader :title="$t('home.import')" :subtitle="$t('import.subtitle')">
      <template #actions>
        <UButton v-if="step > 1" icon="i-lucide-rotate-ccw" color="neutral" variant="ghost" :label="$t('import.newFile')" @click="restart" />
      </template>
    </AppPageHeader>

    <UStepper :model-value="step - 1" :items="steps" disabled class="max-w-2xl" />

    <!-- 1. Upload -->
    <UCard v-if="step === 1">
      <div
        class="border-2 border-dashed rounded-xl py-14 px-6 text-center space-y-4 transition-colors"
        :class="dragging ? 'border-primary bg-primary/5' : 'border-default'"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop"
      >
        <UIcon name="i-lucide-file-spreadsheet" class="size-14 text-primary mx-auto" />
        <p class="text-lg">{{ $t('import.dropHere') }}</p>
        <p class="text-sm text-muted">{{ $t('import.formats') }}</p>
        <UButton icon="i-lucide-upload" :loading="saving" :label="$t('import.chooseFile')" @click="fileInput?.click()" />
        <input ref="fileInput" type="file" accept=".csv,.txt,.tsv,.xlsx,.xls,.ods" class="hidden" @change="upload(($event.target as HTMLInputElement).files?.[0])">
      </div>
    </UCard>

    <!-- 2. Mapping -->
    <template v-else-if="step === 2 && analysis">
      <UCard>
        <div class="flex flex-wrap items-end gap-4">
          <div class="min-w-0">
            <div class="text-sm text-muted">{{ $t('import.stepFile') }}</div>
            <div class="font-semibold truncate">{{ analysis.file_name }} · {{ $t('import.rows', analysis.row_count) }} · {{ $t('import.columns', analysis.columns.length) }}</div>
          </div>
          <UFormField v-if="analysis.sheets.length > 1" :label="$t('import.sheet')" class="w-56">
            <USelect :model-value="sheet ?? undefined" :items="analysis.sheets" @update:model-value="sheet = $event as string; reanalyze()" />
          </UFormField>
          <UFormField v-if="analysis.delimiter" :label="$t('import.delimiter')" class="w-56">
            <USelect :model-value="delimiter ?? undefined" :items="delimiterItems" @update:model-value="delimiter = $event as string; reanalyze()" />
          </UFormField>
        </div>
      </UCard>

      <UCard>
        <template #header><h2 class="font-semibold">{{ $t('import.target') }}</h2></template>
        <div class="grid gap-4 md:grid-cols-2">
          <button
            type="button"
            class="text-start p-4 rounded-xl border-2 transition-colors disabled:opacity-50"
            :class="mode === 'new' ? 'border-primary bg-primary/5' : 'border-default'"
            :disabled="!analysis.may_create"
            @click="mode = 'new'"
          >
            <div class="font-semibold flex items-center gap-2"><UIcon name="i-lucide-plus-square" class="size-5 text-primary" /> {{ $t('import.createEntity') }}</div>
            <p class="text-sm text-muted mt-1">{{ analysis.may_create ? $t('import.everyColumn') : $t('import.adminsOnly') }}</p>
          </button>
          <button
            type="button"
            class="text-start p-4 rounded-xl border-2 transition-colors disabled:opacity-50"
            :class="mode === 'existing' ? 'border-primary bg-primary/5' : 'border-default'"
            :disabled="!importable.length"
            @click="mode = 'existing'"
          >
            <div class="font-semibold flex items-center gap-2"><UIcon name="i-lucide-database" class="size-5 text-primary" /> {{ $t('import.intoExisting') }}</div>
            <p class="text-sm text-muted mt-1">
              <template v-if="analysis.existing[0]">{{ $t('import.fitsWell', { entity: analysis.existing[0].name, percent: Math.round(analysis.existing[0].score * 100) }) }}</template>
              <template v-else>{{ $t('import.mapColumns') }}</template>
            </p>
          </button>
        </div>

        <div v-if="mode === 'new'" class="grid gap-4 md:grid-cols-2 mt-6">
          <UFormField :label="$t('common.name')" :error="errors['entity.name']" required>
            <UInput v-model="entityForm.name" :placeholder="$t('import.nameExample')" />
          </UFormField>
          <UFormField :label="$t('common.technicalNameApi')" :error="errors['entity.slug']" :help="`/api/v1/content/${entityForm.slug}`" required>
            <UInput v-model="entityForm.slug" class="font-mono" />
          </UFormField>
          <UFormField :label="$t('schema.access')" :error="errors['entity.access']">
            <USelect v-model="entityForm.access" :items="[{ value: 'public', label: $t('schema.publicLong') }, { value: 'oauth', label: $t('access.oauthOnly') }]" />
          </UFormField>
          <UFormField :label="$t('schema.labelField')" :help="$t('schema.labelFieldHelp')" :error="errors['entity.label_field']">
            <USelect v-model="entityForm.label_field" :items="labelFieldItems" />
          </UFormField>
        </div>
        <div v-else class="grid gap-4 md:grid-cols-2 mt-6">
          <UFormField :label="$t('import.entity')" required>
            <USelect v-model="target" :items="[{ value: NONE, label: $t('import.pleaseChoose') }, ...importable.map(e => ({ value: e.slug, label: e.name }))]" />
          </UFormField>
          <UFormField :label="$t('import.existingRecords')" :error="errors.key">
            <USelect v-model="key" :items="keyItems" :disabled="target === NONE" />
          </UFormField>
        </div>
      </UCard>

      <UCard v-if="mode === 'new' || target !== NONE" :ui="{ body: 'p-0 sm:p-0' }">
        <template #header>
          <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold">{{ $t('import.mapping') }}</h2>
            <span class="text-sm text-muted">{{ $t('import.imported', { count: importedCount, total: columns.length }) }}</span>
          </div>
        </template>
        <p v-if="errors.columns" class="px-4 pt-4 text-sm text-error">{{ errors.columns }}</p>
        <div class="divide-y divide-default">
          <div v-for="c in columns" :key="c.column" class="p-4 grid gap-4 lg:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]" :class="{ 'opacity-60': !isImported(c) }">
            <div class="min-w-0 flex gap-3">
              <USwitch v-if="mode === 'new'" v-model="c.include" class="mt-0.5" />
              <div class="min-w-0">
                <div class="font-semibold truncate" :title="c.column">{{ c.column }}</div>
                <div class="text-xs text-muted space-y-0.5 mt-1">
                  <div v-for="sample in c.samples" :key="sample" class="truncate font-mono">{{ sample }}</div>
                  <div v-if="c.empty">{{ $t('import.empty', { count: c.empty }) }}</div>
                </div>
              </div>
            </div>

            <!-- Existing entity: choose the field (or create one) -->
            <div v-if="mode === 'existing'" class="grid gap-3 md:grid-cols-2">
              <UFormField :label="$t('import.field')" :error="error(c.column, 'field')">
                <USelect v-model="c.target" :items="targetFieldItems" />
              </UFormField>
              <UFormField v-if="existingField(c)?.type === 'reference'" :label="$t('import.matchBy')" :help="$t('import.matchByHelp', { entity: existingField(c)?.reference ?? '' })" :error="error(c.column, 'match')">
                <USelect :model-value="c.match ?? 'id'" :items="matchItems(existingField(c)?.reference ?? null)" @update:model-value="c.match = $event as string" />
              </UFormField>
              <UFormField v-if="existingField(c)?.type === 'reference'" :error="error(c.column, 'create_missing')" class="md:col-start-2">
                <UCheckbox :id="`import-${c.column}-create-missing`" v-model="c.create_missing" :label="$t('import.createMissing', { entity: entityName(existingField(c)?.reference) })" :disabled="(c.match ?? 'id') === 'id'" />
              </UFormField>
            </div>


            <!-- New field: name, type and options -->
            <div v-if="(mode === 'new' && c.include) || (mode === 'existing' && c.target === NEW_FIELD)" class="grid gap-3 md:grid-cols-2 xl:grid-cols-4" :class="{ 'md:col-start-2': mode === 'existing' }">
              <UFormField :label="$t('common.label')" :error="error(c.column, 'label')">
                <UInput v-model="c.label" />
              </UFormField>
              <UFormField :label="$t('common.technicalName')" :error="error(c.column, 'name')">
                <UInput v-model="c.field" class="font-mono" />
              </UFormField>
              <UFormField :label="$t('library.type')" :error="error(c.column, 'type')">
                <USelect v-model="c.type" :items="typeItems" />
              </UFormField>
              <div class="flex items-end gap-4 pb-1.5">
                <UCheckbox :id="`import-${c.column}-required`" v-model="c.required" :label="$t('fields.required')" :disabled="isGeneratedType(c.type)" />
                <UCheckbox :id="`import-${c.column}-unique`" v-model="c.unique" :label="$t('fields.unique')" :disabled="isGeneratedType(c.type) || c.type === 'text' || c.type === 'boolean'" />
              </div>
              <UFormField v-if="c.type === 'string' || c.type === 'slug'" :label="$t('import.maxLength')" :error="error(c.column, 'length')">
                <UInput v-model.number="c.length" type="number" min="1" max="1000" />
              </UFormField>
              <UFormField v-if="c.type === 'decimal'" :label="$t('fields.scale')" :error="error(c.column, 'scale')">
                <UInput v-model.number="c.scale" type="number" min="0" max="8" />
              </UFormField>
              <UFormField v-if="c.type === 'uuid'" :label="$t('import.uuidVersion')" :error="error(c.column, 'uuid_version')">
                <USelect :model-value="c.uuid_version ?? 7" :items="uuidVersionItems(t)" @update:model-value="c.uuid_version = $event as number" />
              </UFormField>
              <template v-if="c.type === 'reference'">
                <UFormField :label="$t('fields.references')" :error="error(c.column, 'reference')">
                  <USelect :model-value="c.reference ?? undefined" :items="referenceItems" :placeholder="$t('fields.chooseEntity')" @update:model-value="c.reference = $event as string; c.match = $event === NEW_ENTITY ? 'name' : 'id'" />
                </UFormField>
                <template v-if="c.reference === NEW_ENTITY">
                  <UFormField :label="$t('import.newEntityName')" :error="error(c.column, 'new_reference.name')">
                    <UInput v-model="c.new_name" />
                  </UFormField>
                  <UFormField :label="$t('import.newEntitySlug')" :help="$t('import.newEntityHelp')" :error="error(c.column, 'new_reference.slug') || error(c.column, 'new_reference')">
                    <UInput v-model="c.new_slug" class="font-mono" />
                  </UFormField>
                </template>
                <template v-else>
                  <UFormField :label="$t('import.matchBy')" :error="error(c.column, 'match')">
                    <USelect :model-value="c.match ?? 'id'" :items="matchItems(c.reference)" @update:model-value="c.match = $event as string" />
                  </UFormField>
                  <UFormField :error="error(c.column, 'create_missing')" class="flex items-end pb-1.5">
                    <UCheckbox :id="`import-${c.column}-create-missing`" v-model="c.create_missing" :label="$t('import.createMissing', { entity: entityName(c.reference) })" :disabled="(c.match ?? 'id') === 'id'" />
                  </UFormField>
                </template>
                <UFormField class="flex items-end pb-1.5">
                  <UCheckbox :id="`import-${c.column}-repeatable`" v-model="c.repeatable" :label="$t('import.severalValues')" />
                </UFormField>
              </template>
            </div>

            <!-- Yes/no: which values of the file mean yes and which no -->
            <div v-if="isBoolean(c)" class="grid gap-3 md:grid-cols-3 lg:col-start-2">
              <UFormField :label="$t('import.trueValues')" :help="c.true_values.length || c.false_values.length ? undefined : $t('import.booleanDefault')">
                <USelectMenu v-model="c.true_values" :items="c.values.filter(v => !c.false_values.includes(v))" multiple create-item :placeholder="$t('import.chooseValues')" class="w-full" @create="(v: string) => c.true_values.push(v)" />
              </UFormField>
              <UFormField :label="$t('import.falseValues')">
                <USelectMenu v-model="c.false_values" :items="c.values.filter(v => !c.true_values.includes(v))" multiple create-item :placeholder="$t('import.chooseValues')" class="w-full" @create="(v: string) => c.false_values.push(v)" />
              </UFormField>
              <UFormField :label="$t('import.emptyCells')">
                <UCheckbox :id="`import-${c.column}-empty-false`" v-model="c.empty_false" :label="$t('import.emptyFalse')" class="mt-2" />
              </UFormField>
            </div>

            <!-- The values could point to another entity: link with one click -->
            <div v-if="offersLink(c)" class="flex min-w-0 flex-wrap items-center gap-2 text-sm lg:col-start-2">
              <UIcon name="i-lucide-link" class="size-4 text-info" />
              <UButton
                v-for="candidate in c.candidates"
                :key="candidate.entity"
                size="xs"
                color="info"
                variant="soft"
                class="max-w-full"
                :ui="{ label: 'truncate' }"
                :label="$t('import.linkTo', { entity: candidate.name, field: candidate.match_label, found: candidate.found, total: candidate.total })"
                @click="linkTo(c, candidate)"
              />
              <UButton v-if="isAdmin && repeats(c)" size="xs" color="neutral" variant="soft" icon="i-lucide-sparkles" class="max-w-full" :ui="{ label: 'truncate' }" :label="$t('import.makeEntity', { name: c.label || c.column })" @click="linkTo(c, null)" />
            </div>
          </div>
        </div>
      </UCard>

      <div class="flex justify-end gap-3">
        <UButton color="neutral" variant="outline" :label="$t('common.cancel')" @click="restart" />
        <UButton :loading="saving" icon="i-lucide-eye" :label="$t('import.stepPreview')" :disabled="mode === 'existing' && target === NONE" @click="loadPreview" />
      </div>
    </template>

    <!-- 3. Preview / 4. Result -->
    <template v-else-if="(step === 3 && preview) || (step === 4 && result)">
      <div class="grid grid-cols-3 gap-4 text-center">
        <UCard><div class="text-3xl font-bold text-success">{{ (result ?? preview)!.summary.create }}</div><div class="text-sm text-muted">{{ step === 4 ? $t('import.created') : $t('import.willCreate') }}</div></UCard>
        <UCard><div class="text-3xl font-bold text-info">{{ (result ?? preview)!.summary.update }}</div><div class="text-sm text-muted">{{ step === 4 ? $t('import.updated') : $t('import.willUpdate') }}</div></UCard>
        <UCard><div class="text-3xl font-bold text-error">{{ (result ?? preview)!.summary.error }}</div><div class="text-sm text-muted">{{ $t('import.failed') }}</div></UCard>
      </div>

      <UCard v-if="Object.keys((result ?? preview)!.new_references ?? {}).length">
        <template #header><h2 class="font-semibold">{{ step === 4 ? $t('import.referencesCreated') : $t('import.referencesToCreate') }}</h2></template>
        <ul class="space-y-2 text-sm">
          <li v-for="(info, column) in (result ?? preview)!.new_references" :key="column" class="flex flex-wrap items-baseline gap-x-2">
            <UBadge :label="info.new ? $t('import.newEntity') : $t('import.missingRecords')" :color="info.new ? 'primary' : 'info'" variant="subtle" />
            <span class="font-medium">{{ info.entity }}</span>
            <span class="text-muted">{{ $t('import.recordCount', info.count) }} – {{ info.values.join(', ') }}{{ info.count > info.values.length ? ' …' : '' }}</span>
          </li>
        </ul>
      </UCard>

      <UCard v-if="(result ?? preview)!.errors.length">
        <template #header><h2 class="font-semibold text-error">{{ $t('import.failedRows') }}</h2></template>
        <ul class="divide-y divide-default text-sm max-h-96 overflow-y-auto">
          <li v-for="row in (result ?? preview)!.errors" :key="row.line" class="py-2 flex gap-3">
            <UBadge color="error" variant="subtle" :label="$t('import.row', { row: row.line })" class="shrink-0" />
            <span class="min-w-0"><span class="font-medium">{{ row.label || '–' }}</span> <span class="text-muted">{{ row.message }}</span></span>
          </li>
        </ul>
      </UCard>

      <UCard v-if="step === 3 && preview!.rows.length" :ui="{ body: 'p-0 sm:p-0' }">
        <template #header><h2 class="font-semibold">{{ $t('import.firstRows') }}</h2></template>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-elevated/50 text-left">
              <tr>
                <th class="px-3 py-2">{{ $t('import.rowColumn') }}</th>
                <th v-for="field in preview!.fields" :key="field.name" class="px-3 py-2 whitespace-nowrap">{{ field.label }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-default">
              <tr v-for="row in preview!.rows.slice(0, 20)" :key="row.line" :class="{ 'bg-error/5': row.action === 'error' }">
                <td class="px-3 py-2 text-muted">{{ row.line }}</td>
                <td v-for="field in preview!.fields" :key="field.name" class="px-3 py-2 max-w-48 truncate">{{ row.values?.[field.name] ?? '–' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </UCard>

      <div class="flex justify-end gap-3">
        <template v-if="step === 3">
          <UButton color="neutral" variant="outline" :label="$t('common.back')" @click="step = 2" />
          <UButton :loading="saving" icon="i-lucide-play" :disabled="!preview!.summary.create && !preview!.summary.update" :label="$t('import.run', preview!.summary.create + preview!.summary.update)" @click="run" />
        </template>
        <UButton v-else :to="`/entities/${resultSlug}`" icon="i-lucide-arrow-right" :label="$t('import.toRecords')" />
      </div>
    </template>
  </div>
</template>
