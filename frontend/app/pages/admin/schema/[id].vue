<script setup lang="ts">
import type { Entity, Field, FieldGroupDef } from '~/types/api'

definePageMeta({ admin: true })
const route = useRoute()
const id = route.params.id as string
const { loadSession } = useAuth()
const { number } = useFormat()

const { data, refresh } = await useAsyncData(`admin-entity-${id}`, () => useApi()<{ data: Entity }>(`/admin/entities/${id}`))
const { data: all } = await useAsyncData('admin-entities-all', () => useApi()<{ data: Entity[] }>('/admin/entities'))
const entity = computed(() => data.value!.data)
const { t } = useI18n()
useHead({ title: () => t('schema.titleOf', { entity: entity.value.name }) })

const NONE = '-'
const form = reactive({ name: '', slug: '', description: '', access: 'public', label_field: NONE, tree_field: NONE, trash: false, drafts: false, revisions: false, preview_url: '', unique_together: [] as string[][] })
function reset() {
  Object.assign(form, {
    name: entity.value.name,
    slug: entity.value.slug,
    description: entity.value.description ?? '',
    access: entity.value.access,
    label_field: entity.value.label_field ?? NONE,
    trash: entity.value.trash,
    drafts: entity.value.drafts ?? false,
    revisions: entity.value.revisions ?? false,
    preview_url: entity.value.preview_url ?? '',
    tree_field: entity.value.tree_field ?? NONE,
    unique_together: entity.value.unique_together.map(set => [...set])
  })
}
reset()

const { submit, saving, errors } = useSubmit()
// Candidates for the parent field of a tree: references to this entity itself
const selfReferences = computed(() => entity.value.fields.filter(f => f.type === 'reference' && f.reference === entity.value.slug))

async function save() {
  const slugChanged = form.slug !== entity.value.slug
  const res = await submit(() => useApi()(`/admin/entities/${id}`, { method: 'PUT', body: { ...form, label_field: form.label_field === NONE ? null : form.label_field, tree_field: form.tree_field === NONE ? null : form.tree_field, unique_together: form.unique_together.filter(set => set.length) } }))
  if (res) {
    await refresh()
    reset()
    await loadSession()
    if (slugChanged) clearNuxtData()
  }
}

async function remove() {
  if (await submit(() => useApi()(`/admin/entities/${id}`, { method: 'DELETE' }), t('schema.deleted')) !== null) {
    await loadSession()
    await navigateTo('/admin/schema')
  }
}

// Fields
const dialogOpen = ref(false)
const editing = ref<Field | null>(null)
function edit(field: Field | null) {
  editing.value = field
  dialogOpen.value = true
}
async function afterFieldChange() {
  await refresh()
  reset()
}
async function removeField(field: Field) {
  if (await submit(() => useApi()(`/admin/entities/${id}/fields/${field.id}`, { method: 'DELETE' }), t('fields.deleted')) !== null) await afterFieldChange()
}
const drag = useDragSort(move)
async function move(from: number, to: number) {
  const ids = moved(entity.value.fields.map(f => f.id), from, to)
  if (await submit(() => useApi()(`/admin/entities/${id}/fields/order`, { method: 'POST', body: { ids } }), '') !== null) await refresh()
}

// Turn fields into a field group (new, or an existing one with fields of the same names)
const { project } = useAuth()
const convertOpen = ref(false)
const convert = reactive({ fields: [] as string[], mode: 'new' as 'new' | 'existing', group: null as string | null, group_label: '', group_name: '', name: '', label: '' })
const { data: groupData, refresh: refreshGroups } = await useAsyncData('admin-groups', () => useApi()<{ data: FieldGroupDef[] }>('/admin/groups'))
// Fields become a field group - never a block
const fieldGroups = computed(() => (groupData.value?.data ?? []).filter(g => g.kind === 'group'))
const convertible = computed(() => entity.value.fields.filter(f => !['autoincrement', 'uuid', 'slug'].includes(f.type) && f.name !== entity.value.tree_field))
watch(() => convert.group_label, (label) => {
  convert.group_name = slugify(label, 40).replace(/-/g, '_')
  convert.name = convert.group_name
  convert.label = label
})
function openConvert() {
  Object.assign(convert, { fields: [], mode: 'new', group: null, group_label: '', group_name: '', name: '', label: '' })
  refreshGroups()
  convertOpen.value = true
}
async function runConvert() {
  const body = convert.mode === 'new'
    ? { fields: convert.fields, group_label: convert.group_label, group_name: convert.group_name, name: convert.name, label: convert.label }
    : { fields: convert.fields, group: convert.group, name: convert.name || groupData.value?.data.find(g => g.id === convert.group)?.name, label: convert.label || undefined }
  if (await submit(() => useApi()(`/admin/entities/${id}/fields/group`, { method: 'POST', body }), t('schema.converted')) !== null) {
    convertOpen.value = false
    await afterFieldChange()
  }
}

// Copy into another project: the schema, optionally with the records (ids stay, so copying related
// entities one after the other keeps their references)
const { projects, switchProject } = useAuth()
const toast = useToast()
const copyOpen = ref(false)
const copyForm = reactive({ project: null as string | null, slug: '', records: true })
const otherProjects = computed(() => projects.value.filter(p => p.id !== project.value?.id))
function openCopy() {
  Object.assign(copyForm, { project: otherProjects.value[0]?.slug ?? null, slug: entity.value.slug, records: true })
  copyOpen.value = true
}
async function runCopy() {
  const res = await submit(() => useApi()<{ data: { project: { slug: string, name: string }, records: number, media: number, cleared: Record<string, number> } }>(`/admin/entities/${id}/copy`, { method: 'POST', body: copyForm }), '')
  if (!res) return
  copyOpen.value = false
  const { project: target, records, media, cleared } = res.data
  const emptied = Object.entries(cleared).map(([field, count]) => `${field}: ${count}`).join(', ')
  toast.add({
    title: t('copy.done', { entity: entity.value.name, project: target.name }),
    description: [copyForm.records ? t('copy.counts', { records: number(records), media: number(media) }) : t('copy.schemaOnly'), emptied && t('copy.emptied', { fields: emptied })].filter(Boolean).join(' · '),
    color: emptied ? 'warning' : 'success',
    icon: 'i-lucide-copy-check',
    duration: emptied ? 0 : 6000,
    actions: [{ label: t('copy.switch'), onClick: () => { switchProject(target.slug) } }]
  })
}

// Settings, fields and form designer in tabs - the tab is kept in the address (?tab=fields)
const tab = computed({
  get: () => ['settings', 'fields', 'form'].includes(String(route.query.tab)) ? String(route.query.tab) : 'settings',
  set: (value: string) => { navigateTo({ query: { ...route.query, tab: value === 'settings' ? undefined : value } }, { replace: true }) },
})
const tabItems = computed(() => [
  { value: 'settings', label: t('schema.tabSettings') },
  { value: 'fields', label: t('schema.tabFields'), badge: String(entity.value.fields.length) },
  { value: 'form', label: t('schema.tabForm'), badge: entity.value.tabs && entity.value.tabs.length > 1 ? String(entity.value.tabs.length) : undefined },
])

const fieldItems = computed(() => entity.value.fields.filter(f => f.type !== 'text' && f.type !== 'boolean').map(f => ({ value: f.name, label: f.label })))
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <AppPageHeader :title="entity.name" :subtitle="$t('schema.subtitle', { count: number(entity.record_count ?? 0), table: `_${project?.table_prefix ?? ''}${entity.slug}` }, entity.record_count ?? 0)" back="/admin/schema">
      <template #actions>
        <UButton v-if="otherProjects.length" icon="i-lucide-copy" color="neutral" variant="ghost" :label="$t('copy.button')" @click="openCopy" />
        <UButton :to="`/entities/${entity.slug}`" icon="i-lucide-table-2" color="neutral" variant="outline" :label="$t('schema.records')" />
      </template>
    </AppPageHeader>

    <UAlert v-if="entity.managed_by" color="neutral" variant="subtle" icon="i-lucide-lock" :title="$t('plugins.managedBy', { plugin: entity.managed_by })" :description="$t('plugins.managedHelp')" />
    <AppTabs v-model="tab" :items="tabItems" />
    <div v-show="tab === 'settings'" class="space-y-6">
      <UCard>
        <template #header><h2 class="font-semibold">{{ $t('schema.settings') }}</h2></template>
        <form class="grid gap-4 md:grid-cols-2" @submit.prevent="save">
          <UFormField :label="$t('common.name')" :error="errors.name" required><UInput v-model="form.name" /></UFormField>
          <UFormField :label="$t('common.technicalNameApi')" :error="errors.slug" :help="form.slug !== entity.slug ? $t('schema.slugWarning') : `/api/v1/${project?.slug ?? ''}/content/${entity.slug}`" required>
            <UInput v-model="form.slug" class="font-mono" :disabled="!!entity.managed_by" />
          </UFormField>
          <UFormField :label="$t('schema.access')" :error="errors.access">
            <USelect v-model="form.access" :items="[{ value: 'public', label: $t('schema.publicLong') }, { value: 'oauth', label: $t('access.oauthOnly') }]" />
          </UFormField>
          <UFormField :label="$t('schema.labelField')" :error="errors.label_field" :help="$t('schema.labelFieldHelp')">
            <USelect v-model="form.label_field" :items="[{ value: NONE, label: $t('schema.automatic') }, ...entity.fields.map(f => ({ value: f.name, label: f.label }))]" />
          </UFormField>
          <UFormField :label="$t('schema.tree')" :error="errors.tree_field" :help="selfReferences.length ? $t('schema.treeHelp') : $t('schema.treeHint', { entity: entity.name })">
            <USelect v-model="form.tree_field" :items="[{ value: NONE, label: $t('schema.flat') }, ...selfReferences.map(f => ({ value: f.name, label: $t('schema.parentField', { field: f.label }) }))]" :disabled="!selfReferences.length" />
          </UFormField>
          <UFormField :label="$t('common.description')" class="md:col-span-2"><UTextarea v-model="form.description" autoresize :rows="2" /></UFormField>
          <UFormField :label="$t('schema.previewUrl')" class="md:col-span-2" :error="errors.preview_url" :help="$t('schema.previewUrlHelp')">
            <EnvInput v-model="form.preview_url" inline placeholder="https://example.com/api/preview?id={{id}}&token={{token}}" />
          </UFormField>
          <UFormField class="md:col-span-2" :error="errors.revisions" :help="$t('schema.revisionsHelp')">
            <USwitch id="schema-revisions" v-model="form.revisions" :label="$t('schema.revisions')" />
          </UFormField>
          <UFormField class="md:col-span-2" :error="errors.drafts" :help="$t('schema.draftsHelp')">
            <USwitch id="schema-drafts" v-model="form.drafts" :label="$t('schema.drafts')" />
          </UFormField>
          <UFormField class="md:col-span-2" :error="errors.trash" :help="$t('schema.trashHelp')">
            <USwitch id="schema-trash" v-model="form.trash" :label="$t('trash.title')" />
          </UFormField>
          <UFormField :label="$t('schema.uniqueTogether')" class="md:col-span-2" :help="$t('schema.uniqueTogetherHelp')" :error="errors.unique_together || errors.unique">
            <div class="space-y-2">
              <div v-for="(set, index) in form.unique_together" :key="index" class="flex gap-2">
                <USelectMenu v-model="form.unique_together[index]" :items="fieldItems" value-key="value" multiple class="flex-1" />
                <UButton icon="i-lucide-trash-2" color="neutral" variant="ghost" :aria-label="$t('common.remove')" @click="form.unique_together.splice(index, 1)" />
              </div>
              <UButton icon="i-lucide-plus" size="sm" color="neutral" variant="outline" :label="$t('schema.addCombination')" @click="form.unique_together.push([])" />
            </div>
          </UFormField>
          <div class="md:col-span-2 flex justify-end">
            <UButton icon="i-lucide-save" type="submit" :loading="saving" :label="$t('common.save')" />
          </div>
        </form>
      </UCard>

      <UCard v-if="entity.referenced_by?.length">
        <template #header><h2 class="font-semibold">{{ $t('schema.usedBy') }}</h2></template>
        <ul class="text-sm space-y-1">
          <li v-for="ref in entity.referenced_by" :key="`${ref.entity}.${ref.field}`">{{ ref.entity_name }} → {{ ref.field_label }}</li>
        </ul>
      </UCard>

      <UCard>
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h2 class="font-semibold">{{ $t('schema.delete') }}</h2>
            <p class="text-sm text-muted">{{ $t('schema.deleteHelp', { count: number(entity.record_count ?? 0) }) }}</p>
          </div>
          <ConfirmButton v-if="!entity.managed_by" :label="$t('schema.delete')" icon="i-lucide-trash-2" :question="$t('schema.deleteQuestion', { entity: entity.name })" @confirm="remove" />
          <UBadge v-else :label="$t('plugins.managedBy', { plugin: entity.managed_by })" color="neutral" variant="subtle" icon="i-lucide-lock" />
        </div>
      </UCard>
    </div>

    <div v-show="tab === 'fields'" class="space-y-6">
      <UCard :ui="{ body: 'p-0 sm:p-0' }">
        <template #header>
          <div class="flex items-center justify-between">
            <h2 class="font-semibold">{{ $t('fields.title') }}</h2>
            <div class="flex gap-2">
              <UButton icon="i-lucide-layers" size="sm" color="neutral" variant="outline" :label="$t('schema.convert')" @click="openConvert" />
              <UButton icon="i-lucide-plus" size="sm" :label="$t('fields.add')" @click="edit(null)" />
            </div>
          </div>
        </template>
        <ul class="divide-y divide-default">
          <li v-for="(field, index) in entity.fields" :key="field.id" class="flex items-center gap-3 px-4 py-3" v-bind="drag.row(index, entity.fields.length > 1)" :class="drag.rowClass(index)">
            <DragHandle v-if="entity.fields.length > 1" @move="step => drag.move(index, index + step, entity.fields.length)" />
            <UIcon :name="typeIcon(field)" class="size-5 text-muted shrink-0" />
            <button type="button" class="flex-1 min-w-0 text-start group" @click="edit(field)">
              <div class="font-medium group-hover:text-primary truncate">{{ field.label }}</div>
              <div class="text-sm text-muted truncate">
                <span class="font-mono">{{ field.name }}</span> · {{ typeLabel(field, $t) }}<template v-if="field.reference"> → {{ field.reference }}</template><template v-if="field.group"> ({{ field.group.label }})</template><template v-if="field.blocks?.length"> · {{ $t('fields.groupModeBlocks') }}: {{ field.blocks.map(block => block.label).join(', ') }}</template><template v-if="field.repeatable"> · {{ $t('fields.repeatable') }}</template><template v-if="field.translatable"> · {{ $t('fields.translatableShort') }}</template>
              </div>
            </button>
            <div class="hidden sm:flex gap-1">
              <UBadge v-if="field.required" :label="$t('fields.required')" color="neutral" variant="subtle" />
              <UBadge v-if="field.unique" :label="$t('fields.unique')" color="info" variant="subtle" />
              <UBadge v-if="field.name === entity.label_field" :label="$t('schema.display')" color="primary" variant="subtle" />
            </div>
            <UIcon v-if="field.locked" name="i-lucide-lock" class="size-4 text-muted" :title="$t('plugins.lockedField')" />
            <ConfirmButton v-else :label="$t('common.delete')" icon="i-lucide-trash-2" variant="ghost" size="sm" :question="$t('schema.deleteField', { field: field.label })" @confirm="removeField(field)" />
          </li>
        </ul>
      </UCard>
    </div>

    <div v-show="tab === 'form'" class="space-y-6">
      <FormTabsDesigner :entity="entity" @saved="afterFieldChange" />
    </div>

    <FieldDialog v-model:open="dialogOpen" :entity="entity" :field="editing" :entities="all?.data ?? []" @saved="afterFieldChange" />

    <UModal v-model:open="copyOpen" :title="$t('copy.title', { entity: entity.name })">
      <template #body>
        <div class="space-y-4">
          <p class="text-sm text-muted">{{ $t('copy.help') }}</p>
          <UFormField :label="$t('copy.target')" :error="errors.project">
            <USelect :model-value="copyForm.project ?? undefined" :items="otherProjects.map(p => ({ value: p.slug, label: p.name }))" class="w-full" @update:model-value="copyForm.project = $event as string" />
          </UFormField>
          <UFormField :label="$t('copy.slug')" :error="errors.slug"><UInput v-model="copyForm.slug" class="font-mono w-full" /></UFormField>
          <USwitch id="copy-records" v-model="copyForm.records" :label="$t('copy.withRecords')" :description="$t('copy.withRecordsHelp', { count: number(entity.record_count ?? 0) })" />
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-3 w-full">
          <UButton color="neutral" variant="outline" :label="$t('common.cancel')" @click="copyOpen = false" />
          <UButton :loading="saving" :disabled="!copyForm.project || !copyForm.slug" icon="i-lucide-copy" :label="$t('copy.run')" @click="runCopy" />
        </div>
      </template>
    </UModal>

    <UModal v-model:open="convertOpen" :title="$t('schema.convertTitle')">
      <template #body>
        <div class="space-y-4">
          <p class="text-sm text-muted">{{ $t('schema.convertHelp') }}</p>
          <UFormField :label="$t('fields.title')" :error="errors.fields">
            <div class="grid gap-2 sm:grid-cols-2">
              <UCheckbox
                v-for="f in convertible"
                :id="`convert-field-${f.name}`"
                :key="f.name"
                :model-value="convert.fields.includes(f.name)"
                :label="`${f.label} (${typeLabel(f, $t)})`"
                @update:model-value="convert.fields = $event ? [...convert.fields, f.name] : convert.fields.filter(n => n !== f.name)"
              />
            </div>
          </UFormField>
          <URadioGroup v-model="convert.mode" orientation="horizontal" :items="[{ value: 'new', label: $t('schema.newGroup') }, { value: 'existing', label: $t('schema.existingGroup'), disabled: !fieldGroups.length }]" />
          <div v-if="convert.mode === 'new'" class="grid grid-cols-2 gap-4">
            <UFormField :label="$t('schema.groupName')" :error="errors.label" required><UInput v-model="convert.group_label" placeholder="SEO" class="w-full" /></UFormField>
            <UFormField :label="$t('common.technicalName')" :error="errors.name" required><UInput v-model="convert.group_name" class="font-mono w-full" /></UFormField>
          </div>
          <UFormField v-else :label="$t('schema.group')" :help="$t('schema.groupHelp')" :error="errors.group">
            <USelect :model-value="convert.group ?? undefined" :items="fieldGroups.map(g => ({ value: g.id, label: `${g.label} (${g.fields.map(f => f.name).join(', ')})` }))" :placeholder="$t('fields.chooseGroup')" class="w-full" @update:model-value="convert.group = $event as string" />
          </UFormField>
          <UFormField :label="$t('schema.groupFieldName')" :help="$t('schema.groupFieldNameHelp')" :error="errors.name">
            <UInput v-model="convert.name" class="font-mono w-full" />
          </UFormField>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-3 w-full">
          <UButton color="neutral" variant="outline" :label="$t('common.cancel')" @click="convertOpen = false" />
          <UButton :loading="saving" :disabled="!convert.fields.length" :label="$t('schema.convertRun')" @click="runConvert" />
        </div>
      </template>
    </UModal>
  </div>
</template>
