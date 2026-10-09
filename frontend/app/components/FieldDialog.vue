<script setup lang="ts">
import type { CustomFieldInfo, Entity, Field, FieldGroupDef, FieldTypeKey, MediaTypeGroup, Role } from '~/types/api'

// Add or change a field. A new type is applied to existing records by the API (values are
// converted, or nothing changes and the error names the values that do not fit).
// Also used for the fields of a field group: then basePath points to the group and inGroup hides
// what groups do not have (unique, translatable, counters, UUIDs, slugs)
const props = withDefaults(defineProps<{ entity: Entity, field: Field | null, entities: Entity[], basePath?: string, inGroup?: boolean }>(), { basePath: undefined, inGroup: false })
const base = computed(() => props.basePath ?? `/admin/entities/${props.entity.id}`)
const emit = defineEmits<{ saved: [] }>()
const open = defineModel<boolean>('open', { default: false })

const form = reactive({ label: '', name: '', type: 'string' as Field['type'], length: 255 as number | null, scale: 2 as number | null, uuid_version: 7, media_accept: [] as string[], repeatable: false, sortable: true, repeat_min: null as number | null, repeat_max: null as number | null, translatable: false, group: null as string | null, pattern: '', pattern_message: '', options: [] as { value: string, label: string }[], slug_source: null as string | null, required: false, unique: false, reference: null as string | null, match: 'id', read_roles: [] as string[], write_roles: [] as string[], searchable: true, filterable: true, search_weight: 1, blocks: [] as string[], block_categories: [] as string[], groupMode: 'group' as 'group' | 'blocks', min_value: null as number | null, max_value: null as number | null, slider: false })
// Roles that may see / change the field (empty: everyone with permissions for the entity)
const { data: roleData } = await useAsyncData('field-dialog-roles', () => useApi()<{ data: Role[] }>('/admin/roles'))
const roleItems = computed(() => (roleData.value?.data ?? []).map(role => ({ value: role.slug, label: role.name })))
const { submit, saving, errors } = useSubmit()
const { t } = useI18n()

// Translatable: text types, in projects with more than one language
const { project } = useAuth()
const languages = computed(() => project.value?.languages ?? [])
const canRepeat = computed(() => !['autoincrement', 'uuid', 'slug', 'boolean', 'order', 'daterange', 'json', 'code'].includes(form.type) && !form.type.includes('.'))
const canTranslate = computed(() => (['string', 'text', 'markdown', 'url', 'slug', 'group', 'regex'].includes(form.type) || form.type.includes('.')))

// Slugs can be made from a text field of the same entity
const NO_SOURCE = '-'
const slugSourceItems = computed(() => [
  { value: NO_SOURCE, label: t('fields.slugManual') },
  ...props.entity.fields.filter(f => ['string', 'text', 'email', 'markdown'].includes(f.type) && f.name !== props.field?.name).map(f => ({ value: f.name, label: f.label }))
])

// Allowed files of media fields: "image/*" etc. and every single type, grouped by main type
const { data: schemaOptions } = await useAsyncData('schema-options', () => useApi()<{ data: { media_types: MediaTypeGroup[] } }>('/admin/schema-options'))
const mediaTypeItems = computed(() => (schemaOptions.value?.data.media_types ?? []).map(group => [{ type: 'label' as const, label: group.group }, ...group.items]))

watch(open, (value) => {
  if (!value) return
  errors.value = {}
  const f = props.field
  Object.assign(form, f
    ? { label: f.label, name: f.name, type: f.type, length: f.length ?? 255, scale: f.scale ?? 2, uuid_version: f.uuid_version ?? 7, media_accept: [...(f.media_accept ?? [])], repeatable: f.repeatable ?? false, sortable: f.sortable ?? true, repeat_min: f.repeat_min ?? null, repeat_max: f.repeat_max ?? null, translatable: f.translatable ?? false, group: f.group?.id ?? null, pattern: f.pattern ?? '', pattern_message: f.pattern_message ?? '', options: (f.options ?? []).map(o => ({ ...o })), slug_source: f.slug_source, required: f.required, unique: f.unique, reference: f.reference, match: 'id', read_roles: [...(f.read_roles ?? [])], write_roles: [...(f.write_roles ?? [])], searchable: f.searchable ?? true, filterable: f.filterable ?? true, search_weight: f.search_weight ?? 1, blocks: [...(f.block_ids ?? (f.blocks ?? []).map(g => g.id))], block_categories: [...(f.block_categories ?? [])], groupMode: f.blocks?.length || f.block_categories?.length ? 'blocks' as const : 'group' as const, min_value: f.min_value ?? null, max_value: f.max_value ?? null, slider: f.slider ?? false }
    : { label: '', name: '', type: 'string', length: 255, scale: 2, uuid_version: 7, media_accept: [], repeatable: false, sortable: true, repeat_min: null, repeat_max: null, translatable: false, group: null, pattern: '', pattern_message: '', options: [], slug_source: null, required: false, unique: false, reference: null, match: 'id', read_roles: [], write_roles: [], searchable: true, filterable: true, search_weight: 1, blocks: [], block_categories: [], groupMode: 'group' as const, min_value: null, max_value: null, slider: false })
})

// Counter: the database numbers the records, so it is always unique and never filled in by hand
const generated = computed(() => isGeneratedType(form.type))
watch(() => form.type, (type) => {
  if (isGeneratedType(type)) Object.assign(form, { unique: true, required: false })
  else if (type === 'order') Object.assign(form, { unique: false, required: false })
  else if (type === 'slug') form.unique = true
  else if (type === 'uuid' && !props.field) form.unique = true
})
const hasCounter = computed(() => props.entity.fields.some(f => isGeneratedType(f.type) && f.id !== props.field?.id))
// At most one order field and one UUID per entity
const hasOrder = computed(() => props.entity.fields.some(f => f.type === 'order' && f.id !== props.field?.id))
const hasUuid = computed(() => props.entity.fields.some(f => f.type === 'uuid' && f.id !== props.field?.id))

// Technical name follows the label for new fields
watch(() => form.label, (label) => {
  if (!props.field) form.name = label.toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 64)
})

// Groups and media fields cannot be converted; groups have no counters, UUIDs or slugs
// Fields of plugins cannot be converted either
const typeLocked = (type: string) => !!props.field && props.field.type !== type && (['media', 'group'].includes(props.field.type) || ['media', 'group'].includes(type) || props.field.type.includes('.') || type.includes('.'))
// Field types of the active plugins ("geo.point")
const { data: pluginTypeData } = useLazyAsyncData('plugin-field-types', () => useApi()<{ data: CustomFieldInfo[] }>('/admin/plugins/field-types').catch(() => ({ data: [] as CustomFieldInfo[] })))
const typeItems = computed(() => [
  ...FIELD_TYPES
    .filter(value => !props.inGroup || !['autoincrement', 'uuid', 'slug', 'order'].includes(value))
    .map(value => ({ value: value as string, label: t(`fieldTypes.${value}`), icon: FIELD_TYPE_ICONS[value], disabled: (hasCounter.value && isGeneratedType(value)) || (hasOrder.value && value === 'order') || (hasUuid.value && value === 'uuid') || typeLocked(value) })),
  ...((pluginTypeData.value?.data ?? []).length ? [{ type: 'label' as const, label: t('plugins.fieldTypes') }] : []),
  ...(pluginTypeData.value?.data ?? []).map(custom => ({ value: custom.type, label: custom.label, icon: custom.icon, disabled: typeLocked(custom.type) })),
])

const isNumberType = computed(() => form.type === 'integer' || form.type === 'decimal')
const numberOrNull = (value: unknown) => value === '' || value === undefined || value === null || Number.isNaN(Number(value)) ? null : Number(value)

// Try the pattern right in the dialog
const patternTest = ref('')
const patternResult = computed(() => matchesPattern(form.pattern, patternTest.value))

// Group field as blocks (page builder): several groups, one per item - in groups too (nested blocks)
const isBlocks = computed(() => form.type === 'group' && form.groupMode === 'blocks')
const groupModeItems = computed(() => [
  { value: 'group', label: t('fields.groupModeGroup'), description: t('fields.groupModeGroupHelp') },
  { value: 'blocks', label: t('fields.groupModeBlocks'), description: t('fields.groupModeBlocksHelp') },
])
// Block lists offer blocks, group fields field groups - never the group itself
const choosable = (kind: 'group' | 'block') => (groupData.value?.data ?? []).filter(g => g.kind === kind && (!props.inGroup || g.id !== props.entity.id))
const blockItems = computed(() => byCategory(choosable('block')).map(section => [
  // (labels have a value too - for the types of the select; they cannot be chosen)
  ...(section.category ? [{ type: 'label' as const, value: `category:${section.category}`, label: section.category, description: '' }] : []),
  ...section.items.map(g => ({ type: 'item' as const, value: g.id, label: g.label, description: g.fields.map(f => f.name).join(', ') })),
]))

// Field groups of the project (a group cannot contain itself)
const { data: groupData } = await useAsyncData('admin-groups', () => useApi()<{ data: FieldGroupDef[] }>('/admin/groups'))
// Whole categories of blocks (also blocks added later)
// "*": every block of the project (as the columns of Columns) - in the editor it offers what the list around it offers
const blockCategoryItems = computed(() => [{ value: '*', label: t('fields.allBlocks') }, ...categoriesOf(choosable('block')).map(category => ({ value: category, label: category }))])
const groupItems = computed(() => choosable('group').map(g => ({ value: g.id, label: `${g.label} (${g.fields.map(f => f.name).join(', ')})` })))
watch(open, (value) => { if (value) refreshNuxtData('admin-groups') })
const typeChanges = computed(() => !!props.field && (props.field.type !== form.type || (form.type === 'reference' && props.field.reference !== form.reference)))
const becomesReference = computed(() => form.type === 'reference' && (props.field?.type !== 'reference'))
const matchItems = computed(() => {
  const target = props.entities.find(e => e.slug === form.reference)
  return [{ value: 'id', label: 'ID' }, ...(target?.fields ?? []).filter(f => f.type !== 'reference').map(f => ({ value: f.name, label: f.label }))]
})

async function save() {
  const body = {
    label: form.label,
    name: form.name,
    type: form.type,
    length: ['string', 'slug', 'regex'].includes(form.type) ? form.length : null,
    scale: form.type === 'decimal' ? form.scale : null,
    uuid_version: form.type === 'uuid' ? form.uuid_version : null,
    media_accept: form.type === 'media' ? form.media_accept : null,
    repeatable: (canRepeat.value && form.repeatable) || isBlocks.value,
    sortable: form.sortable,
    repeat_min: form.repeatable ? form.repeat_min || null : null,
    repeat_max: form.repeatable ? form.repeat_max || null : null,
    slug_source: form.type === 'slug' ? form.slug_source : null,
    translatable: !props.inGroup && canTranslate.value && form.translatable,
    group: form.type === 'group' && !isBlocks.value ? form.group : null,
    // Blocks: the groups an item can be of - always a list
    ...(isBlocks.value ? { blocks: form.blocks, block_categories: form.block_categories } : {}),
    pattern: form.type === 'regex' ? form.pattern : null,
    pattern_message: form.type === 'regex' ? form.pattern_message || null : null,
    options: form.type === 'enum' ? form.options.filter(o => o.value.trim()) : null,
    // Numbers: range and slider
    min_value: isNumberType.value ? form.min_value : null,
    max_value: isNumberType.value ? form.max_value : null,
    slider: isNumberType.value && form.slider,
    required: form.required,
    unique: form.unique,
    reference: form.type === 'reference' ? form.reference : null,
    ...(props.inGroup ? {} : { read_roles: form.read_roles, write_roles: form.write_roles, searchable: form.searchable, filterable: form.filterable, search_weight: form.search_weight }),
    ...(becomesReference.value && props.field ? { match: form.match } : {})
  }
  const res = await submit(() => props.field
    ? useApi()(`${base.value}/fields/${props.field.id}`, { method: 'PUT', body })
    : useApi()(`${base.value}/fields`, { method: 'POST', body }), props.field ? t('fields.saved') : t('fields.created'))
  if (res) {
    open.value = false
    emit('saved')
  }
}
</script>

<template>
  <UModal v-model:open="open" :title="field ? $t('fields.titleOf', { field: field.label }) : $t('fields.new')">
    <template #body>
      <div class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <UFormField :label="$t('common.label')" :error="errors.label" required><UInput v-model="form.label" autofocus /></UFormField>
          <UFormField :label="$t('common.technicalName')" :error="errors.name" :help="field?.locked ? $t('plugins.lockedField') : undefined" required><UInput v-model="form.name" class="font-mono" :disabled="!!field?.locked" /></UFormField>
        </div>
        <UFormField :label="$t('library.type')" :error="errors.type">
          <USelect v-model="form.type" :items="typeItems" :disabled="!!field?.locked" />
        </UFormField>
        <UAlert v-if="field?.translatable && !form.translatable && languages.length > 1" color="warning" variant="subtle" icon="i-lucide-languages" :title="$t('fields.translationsLost')" />
        <UAlert v-if="typeChanges && entity.record_count" color="warning" variant="subtle" icon="i-lucide-triangle-alert" :title="$t('fields.convertValues', entity.record_count ?? 0)" />
        <UFormField v-if="form.type === 'slug'" :label="$t('fields.slugSource')" :help="$t('fields.slugSourceHelp')" :error="errors.slug_source">
          <USelect :model-value="form.slug_source ?? NO_SOURCE" :items="slugSourceItems" @update:model-value="form.slug_source = $event === NO_SOURCE ? null : $event as string" />
        </UFormField>
        <UFormField v-if="['string', 'slug', 'regex'].includes(form.type)" :label="$t('fields.maxLength')" :error="errors.length">
          <UInput v-model.number="form.length" type="number" min="1" max="1000" />
        </UFormField>
        <UFormField v-if="form.type === 'decimal'" :label="$t('fields.scale')" :error="errors.scale">
          <UInput v-model.number="form.scale" type="number" min="0" max="8" />
        </UFormField>
        <template v-if="isNumberType">
          <div class="grid grid-cols-2 gap-3">
            <UFormField :label="$t('fields.minValue')" :error="errors.min_value">
              <UInput :model-value="form.min_value ?? undefined" type="number" :step="form.type === 'decimal' ? 'any' : 1" :placeholder="$t('fields.noLimit')" class="w-full" @update:model-value="form.min_value = numberOrNull($event)" />
            </UFormField>
            <UFormField :label="$t('fields.maxValue')" :error="errors.max_value">
              <UInput :model-value="form.max_value ?? undefined" type="number" :step="form.type === 'decimal' ? 'any' : 1" :placeholder="$t('fields.noLimit')" class="w-full" @update:model-value="form.max_value = numberOrNull($event)" />
            </UFormField>
          </div>
          <UFormField :help="$t('fields.sliderHelp')" :error="errors.slider">
            <USwitch v-model="form.slider" :label="$t('fields.slider')" :disabled="form.min_value === null || form.max_value === null" />
          </UFormField>
        </template>
        <UFormField v-if="form.type === 'enum'" :label="$t('fields.options')" :help="$t('fields.optionsHelp')" :error="errors.options" required>
          <div class="space-y-2">
            <div v-for="(option, index) in form.options" :key="index" class="flex gap-2">
              <UInput v-model="option.value" :placeholder="$t('fields.optionValue')" class="font-mono flex-1" />
              <UInput v-model="option.label" :placeholder="$t('fields.optionLabel')" class="flex-1" />
              <UButton icon="i-lucide-x" color="neutral" variant="ghost" :aria-label="$t('common.remove')" @click="form.options.splice(index, 1)" />
            </div>
            <UButton icon="i-lucide-plus" size="sm" color="neutral" variant="outline" :label="$t('fields.addOption')" @click="form.options.push({ value: '', label: '' })" />
          </div>
        </UFormField>
        <template v-if="form.type === 'regex'">
          <UFormField :label="$t('fields.pattern')" :help="$t('fields.patternHelp', { pattern: '^[A-Z]{2}-\\d{4}$', example: 'AB-1234' })" :error="errors.pattern" required>
            <UInput v-model="form.pattern" placeholder="^[A-Z]{2}-\d{4}$" class="font-mono w-full" />
          </UFormField>
          <UFormField :label="$t('fields.patternMessage')" :help="$t('fields.patternMessageHelp')" :error="errors.pattern_message">
            <UInput v-model="form.pattern_message" class="w-full" />
          </UFormField>
          <UFormField :label="$t('fields.tryIt')">
            <UInput v-model="patternTest" :placeholder="$t('fields.testValue')" class="font-mono w-full" :color="patternResult === false ? 'error' : patternResult ? 'success' : undefined" :highlight="patternResult !== null">
              <template #trailing>
                <UIcon v-if="patternResult !== null" :name="patternResult ? 'i-lucide-check' : 'i-lucide-x'" :class="patternResult ? 'text-success' : 'text-error'" />
              </template>
            </UInput>
          </UFormField>
        </template>
        <UFormField v-if="form.type === 'group'" :error="errors.blocks">
          <URadioGroup v-model="form.groupMode" :items="groupModeItems" :disabled="!!field" variant="card" orientation="horizontal" :ui="{ fieldset: 'grid sm:grid-cols-2 gap-2', item: 'w-full' }" />
        </UFormField>
        <UFormField v-if="isBlocks" :label="$t('fields.blockTypes')" :help="blockItems.length ? $t('fields.blockTypesHelp') : $t('fields.blockFirst')" :error="errors.blocks" required>
          <USelectMenu v-model="form.blocks" :items="blockItems" value-key="value" multiple :placeholder="$t('fields.chooseBlocks')" class="w-full" />
        </UFormField>
        <UFormField v-if="isBlocks" :label="$t('fields.blockCategories')" :help="$t('fields.blockCategoriesHelp')" :error="errors.block_categories">
          <USelectMenu v-model="form.block_categories" :items="blockCategoryItems" value-key="value" multiple :placeholder="$t('fields.chooseCategories')" class="w-full" />
        </UFormField>
        <UFormField v-if="form.type === 'group' && !isBlocks" :label="$t('fieldTypes.group')" :help="groupItems.length ? $t('fields.groupHelp') : $t('fields.groupFirst')" :error="errors.group">
          <USelect :model-value="form.group ?? undefined" :items="groupItems" :placeholder="$t('fields.chooseGroup')" :disabled="!!field" class="w-full" @update:model-value="form.group = $event as string" />
        </UFormField>
        <UFormField v-if="form.type === 'uuid'" :label="$t('fields.uuidVersion')" :help="$t('fields.uuidHelp')" :error="errors.uuid_version">
          <USelect v-model="form.uuid_version" :items="uuidVersionItems(t)" />
        </UFormField>
        <div v-if="canRepeat && !isBlocks" class="rounded-md border border-default p-3 space-y-3">
          <UCheckbox id="field-repeatable" v-model="form.repeatable" :label="form.type === 'media' ? $t('fields.multipleFiles') : $t('fields.repeatableList')" :description="$t('fields.repeatableHelp')" />
          <template v-if="form.repeatable">
            <div class="grid grid-cols-2 gap-4">
              <UFormField :label="$t('fields.min')" :help="$t('fields.minHelp')" :error="errors.repeat_min">
                <UInput :model-value="form.repeat_min ?? undefined" type="number" min="0" placeholder="0" @update:model-value="form.repeat_min = $event === '' || $event === undefined ? null : Number($event)" />
              </UFormField>
              <UFormField :label="$t('fields.max')" :help="$t('fields.maxHelp')" :error="errors.repeat_max">
                <UInput :model-value="form.repeat_max ?? undefined" type="number" min="1" :placeholder="$t('clients.unlimited')" @update:model-value="form.repeat_max = $event === '' || $event === undefined ? null : Number($event)" />
              </UFormField>
            </div>
            <UCheckbox id="field-sortable" v-model="form.sortable" :label="$t('fields.sortable')" />
          </template>
          <p v-if="errors.repeatable" class="text-sm text-error">{{ errors.repeatable }}</p>
        </div>
        <UFormField v-if="form.type === 'media'" :label="$t('fields.mediaAccept')" :help="$t('fields.mediaAcceptHelp')" :error="errors.media_accept">
          <USelectMenu v-model="form.media_accept" :items="mediaTypeItems" value-key="value" multiple :placeholder="$t('fields.allTypes')" :search-input="{ placeholder: $t('fields.searchType') }" class="w-full" />
        </UFormField>
        <UAlert
          v-if="generated"
          color="info"
          variant="subtle"
          icon="i-lucide-list-ordered"
          :title="$t('fields.counterTitle')"
          :description="$t('fields.counterHelp')"
        />
        <template v-if="form.type === 'reference'">
          <UFormField :label="$t('fields.references')" :error="errors.reference">
            <USelect :model-value="form.reference ?? undefined" :items="entities.map(e => ({ value: e.slug, label: e.name }))" :placeholder="$t('fields.chooseEntity')" @update:model-value="form.reference = $event as string" />
          </UFormField>
          <UFormField v-if="becomesReference && field" :label="$t('fields.match')" :help="$t('fields.matchHelp')" :error="errors.match">
            <USelect v-model="form.match" :items="matchItems" />
          </UFormField>
        </template>
        <div class="flex gap-6">
          <UCheckbox id="field-required" v-model="form.required" :label="$t('fields.requiredField')" :disabled="generated || form.type === 'order'" />
          <UCheckbox
            v-if="canTranslate && !inGroup"
            id="field-translatable"
            v-model="form.translatable"
            :label="$t('fields.translatable')"
            :disabled="languages.length < 2"
            :title="languages.length < 2 ? $t('fields.oneLanguage') : $t('fields.perLanguage', { languages: languages.join(', ') })"
          />
          <UCheckbox v-if="!inGroup" id="field-unique" v-model="form.unique" :label="$t('fields.unique')" :disabled="generated || form.repeatable || (['text', 'boolean', 'media', 'markdown', 'slug', 'group', 'order', 'json', 'code'].includes(form.type) || form.type.includes('.'))" />
        </div>
        <p v-if="errors.unique" class="text-sm text-error">{{ errors.unique }}</p>
        <div v-if="!inGroup" class="flex flex-wrap gap-x-6 gap-y-2">
          <UCheckbox id="field-filterable" v-model="form.filterable" :label="$t('fields.filterable')" :description="$t('fields.filterableHelp')" />
          <UCheckbox id="field-searchable" :model-value="form.searchable && form.filterable" :disabled="!form.filterable" :label="$t('fields.searchable')" :description="$t('fields.searchableHelp')" @update:model-value="form.searchable = !!$event" />
        </div>
        <UFormField v-if="!inGroup && form.searchable && form.filterable" :label="$t('fields.searchWeight')" :help="$t('fields.searchWeightHelp')" :error="errors.search_weight">
          <div class="flex items-center gap-3">
            <USlider v-model="form.search_weight" :min="1" :max="10" :step="1" class="flex-1" />
            <span class="w-8 text-right font-mono text-sm tabular-nums">{{ form.search_weight }}×</span>
          </div>
        </UFormField>
        <div v-if="!inGroup && roleItems.length" class="grid gap-4 border-t border-default pt-4 sm:grid-cols-2">
          <UFormField :label="$t('fields.readRoles')" :help="$t('fields.readRolesHelp')" :error="errors.read_roles">
            <USelectMenu v-model="form.read_roles" :items="roleItems" value-key="value" multiple :placeholder="$t('fields.everyone')" class="w-full" />
          </UFormField>
          <UFormField :label="$t('fields.writeRoles')" :help="$t('fields.writeRolesHelp')" :error="errors.write_roles">
            <USelectMenu v-model="form.write_roles" :items="roleItems" value-key="value" multiple :placeholder="$t('fields.everyone')" class="w-full" />
          </UFormField>
        </div>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-3 w-full">
        <UButton color="neutral" variant="outline" :label="$t('common.cancel')" @click="open = false" />
        <UButton icon="i-lucide-save" :loading="saving" :label="$t('common.save')" @click="save" />
      </div>
    </template>
  </UModal>
</template>
