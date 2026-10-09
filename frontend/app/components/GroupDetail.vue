<script setup lang="ts">
import type { Entity, Field, FieldGroupDef } from '~/types/api'

// One field group or block: name and its fields (the same field dialog as the schema, without what
// groups do not have). Changes apply wherever it is used at once. Unused, it can change its kind.
const route = useRoute()
const id = route.params.id as string

const { data, refresh } = await useAsyncData(`admin-group-${id}`, () => useApi()<{ data: FieldGroupDef }>(`/admin/groups/${id}`))
const { data: all } = await useAsyncData('admin-entities-all', () => useApi()<{ data: Entity[] }>('/admin/entities'))
const group = computed(() => data.value!.data)
const { t } = useI18n()
const isBlock = computed(() => group.value.kind === 'block')
// Texts of the kind: groups.* or blockTypes.*
const k = (key: string, params?: Record<string, unknown>) => t(`${isBlock.value ? 'blockTypes' : 'groups'}.${key}`, params ?? {})
const base = computed(() => isBlock.value ? '/admin/blocks' : '/admin/groups')
useHead({ title: () => k('titleOf', { group: group.value.label }) })
// Opened under the other kind's address: to its own
if (!route.path.startsWith(base.value)) await navigateTo(`${base.value}/${id}`, { replace: true })

// The field dialog works with an entity - the group stands in for it
const asEntity = computed(() => ({ id: group.value.id, slug: '', name: group.value.label, fields: group.value.fields, record_count: 0, languages: [] }) as unknown as Entity)

const form = reactive({ label: '', name: '', category: '', description: '' })
function reset() {
  Object.assign(form, { label: group.value.label, name: group.value.name, category: group.value.category ?? '', description: group.value.description ?? '' })
}
// Suggestions: the categories of all blocks and field groups
const { data: allGroups } = await useAsyncData('admin-groups', () => useApi()<{ data: FieldGroupDef[] }>('/admin/groups'))
const categories = computed(() => categoriesOf(allGroups.value?.data ?? []))

// Template of a block (Twig, sandboxed): its HTML for websites without a template of their own
const template = ref(group.value.template ?? '')
watch(() => group.value.template, (value) => { template.value = value ?? '' })
const templateDirty = computed(() => template.value !== (group.value.template ?? ''))
const tried = ref<{ html: string } | null>(null)
const { submit: submitTemplate, saving: savingTemplate, errors: templateErrors } = useSubmit()
async function tryTemplate() {
  const res = await submitTemplate(() => useApi()<{ data: { html: string } }>(`/admin/groups/${id}/render`, { method: 'POST', body: { template: template.value } }), '')
  tried.value = res ? res.data : null
}
async function saveTemplate() {
  if (await submitTemplate(() => useApi()(`/admin/groups/${id}`, { method: 'PUT', body: { template: template.value } }), t('groups.templateSaved')) !== null) {
    await refresh()
    await tryTemplate()
  }
}
// The example in a frame of its own (no scripts, no styles of the admin app)
const triedDoc = computed(() => tried.value ? `<!doctype html><meta charset="utf-8"><style>body{font:15px/1.5 system-ui,sans-serif;margin:16px;color:#18181b}img{max-width:100%;height:auto}</style>${tried.value.html}` : '')
const fieldNames = computed(() => group.value.fields.map(f => f.name))
reset()
const { submit, saving, errors } = useSubmit()

async function save() {
  if (await submit(() => useApi()(`/admin/groups/${id}`, { method: 'PUT', body: form })) !== null) {
    await refresh()
    reset()
  }
}

const dialogOpen = ref(false)
const editing = ref<Field | null>(null)
function edit(field: Field | null) {
  editing.value = field
  dialogOpen.value = true
}
async function removeField(field: Field) {
  if (await submit(() => useApi()(`/admin/groups/${id}/fields/${field.id}`, { method: 'DELETE' }), t('fields.deleted')) !== null) await refresh()
}
const drag = useDragSort(move)
async function move(from: number, to: number) {
  const ids = moved(group.value.fields.map(f => f.id), from, to)
  if (await submit(() => useApi()(`/admin/groups/${id}/fields/order`, { method: 'POST', body: { ids } }), '') !== null) await refresh()
}
async function remove() {
  if (await submit(() => useApi()(`/admin/groups/${id}`, { method: 'DELETE' }), k('deleted')) !== null) await navigateTo(base.value)
}
// Block ↔ field group (only while nothing uses it)
async function switchKind() {
  const kind = isBlock.value ? 'group' : 'block'
  if (await submit(() => useApi()(`/admin/groups/${id}`, { method: 'PUT', body: { kind } }), t(kind === 'block' ? 'groups.becameBlock' : 'groups.becameGroup')) !== null) {
    refreshNuxtData(['admin-groups', 'admin-groups-group', 'admin-groups-block'])
    await navigateTo(`${kind === 'block' ? '/admin/blocks' : '/admin/groups'}/${id}`)
  }
}
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <AppPageHeader :title="group.label" :subtitle="isBlock ? $t('groups.kindBlock') : $t('groups.kindGroup')" :back="base">
      <template #actions>
        <UBadge v-if="group.managed_by" :label="group.managed_by === 'core' ? $t('plugins.managedByCore') : $t('plugins.managedBy', { plugin: group.managed_by })" color="neutral" variant="subtle" icon="i-lucide-lock" />
        <UButton v-if="!group.usage_count && !group.managed_by" :icon="isBlock ? 'i-lucide-layers' : 'i-lucide-boxes'" color="neutral" variant="ghost" :label="isBlock ? $t('groups.toGroup') : $t('groups.toBlock')" :loading="saving" @click="switchKind" />
        <ConfirmButton v-if="!group.usage_count && !group.managed_by" :label="$t('common.delete')" icon="i-lucide-trash-2" variant="ghost" :question="k('deleteQuestion', { group: group.label })" @confirm="remove" />
      </template>
    </AppPageHeader>

    <UAlert v-if="group.usage_count" color="info" variant="subtle" icon="i-lucide-info" :title="$t('groups.usedBy', group.usage_count)" :description="$t('groups.usedByHelp')" />

    <UCard>
      <form class="grid gap-4 md:grid-cols-2" @submit.prevent="save">
        <UFormField :label="$t('common.name')" :error="errors.label" required><UInput v-model="form.label" class="w-full" /></UFormField>
        <UFormField :label="$t('common.technicalName')" :error="errors.name" required><UInput v-model="form.name" class="font-mono w-full" :disabled="!!group.managed_by" /></UFormField>
        <UFormField :label="$t('groups.category')" :help="$t('groups.categoryHelp')" :error="errors.category" class="md:col-span-2">
          <UInputMenu v-model="form.category" :items="categories" create-item class="w-full sm:w-80" :placeholder="$t('groups.noCategory')" @create="(item: string) => { form.category = item }" />
        </UFormField>
        <UFormField :label="$t('common.description')" class="md:col-span-2"><UTextarea v-model="form.description" autoresize :rows="2" class="w-full" /></UFormField>
        <div class="md:col-span-2 flex justify-end"><UButton icon="i-lucide-save" type="submit" :loading="saving" :label="$t('common.save')" /></div>
      </form>
    </UCard>

    <UCard v-if="isBlock">
      <template #header>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 class="font-semibold">{{ $t('groups.template') }}</h2>
            <p class="text-sm text-muted">{{ $t('groups.templateHelp') }}</p>
          </div>
          <div class="flex gap-2">
            <UButton icon="i-lucide-flask-conical" color="neutral" variant="outline" size="sm" :loading="savingTemplate" :label="$t('groups.templateTry')" @click="tryTemplate" />
            <UButton icon="i-lucide-save" size="sm" :loading="savingTemplate" :disabled="!templateDirty" :label="$t('common.save')" @click="saveTemplate" />
          </div>
        </div>
      </template>
      <div class="grid gap-4 lg:grid-cols-2">
        <UFormField :error="templateErrors.template">
          <CodeEditor v-model="template" class="w-full" :placeholder="`<section class=&quot;${group.name}&quot;>\n  <h2>{{ block.title }}</h2>\n</section>`" />
          <template #help>
            <span class="text-xs">{{ $t('groups.templateVars') }} <code v-for="name in fieldNames" :key="name" class="me-1 rounded bg-elevated px-1 font-mono">block.{{ name }}</code></span>
          </template>
        </UFormField>
        <div class="min-w-0 space-y-2">
          <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ $t('groups.templateExample') }}</p>
          <iframe v-if="tried" :srcdoc="triedDoc" sandbox="" class="h-64 w-full rounded-md border border-default bg-white" :title="$t('groups.templateExample')" />
          <p v-else class="rounded-md border border-dashed border-default p-4 text-sm text-muted">{{ $t('groups.templateTryHint') }}</p>
        </div>
      </div>
    </UCard>

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <template #header>
        <div class="flex items-center justify-between">
          <h2 class="font-semibold">{{ $t('fields.title') }}</h2>
          <UButton icon="i-lucide-plus" size="sm" :label="$t('fields.add')" @click="edit(null)" />
        </div>
      </template>
      <ul class="divide-y divide-default">
        <li v-for="(field, index) in group.fields" :key="field.id" class="flex items-center gap-3 px-4 py-3" v-bind="drag.row(index, group.fields.length > 1)" :class="drag.rowClass(index)">
          <DragHandle v-if="group.fields.length > 1" @move="step => drag.move(index, index + step, group.fields.length)" />
          <UIcon :name="typeIcon(field)" class="size-5 text-muted shrink-0" />
          <button type="button" class="flex-1 min-w-0 text-start group" @click="edit(field)">
            <div class="font-medium group-hover:text-primary truncate">{{ field.label }}</div>
            <div class="text-sm text-muted truncate">
              <span class="font-mono">{{ field.name }}</span> · {{ typeLabel(field, $t) }}<template v-if="field.group"> ({{ field.group.label }})</template><template v-if="field.repeatable"> · {{ $t('fields.repeatable') }}</template>
            </div>
          </button>
          <UBadge v-if="field.required" :label="$t('fields.required')" color="neutral" variant="subtle" class="hidden sm:inline-flex" />
          <UIcon v-if="field.locked" name="i-lucide-lock" class="size-4 text-muted" :title="$t('plugins.lockedField')" />
          <ConfirmButton v-else :label="$t('common.delete')" icon="i-lucide-trash-2" variant="ghost" size="sm" :question="k('removeField', { field: field.label })" @confirm="removeField(field)" />
        </li>
      </ul>
    </UCard>

    <FieldDialog v-model:open="dialogOpen" :entity="asEntity" :field="editing" :entities="all?.data ?? []" :base-path="`/admin/groups/${id}`" in-group @saved="refresh" />
  </div>
</template>
