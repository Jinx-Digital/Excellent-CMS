<script setup lang="ts">
import type { Actor, ContentRecord, DeleteResult, Entity, Field, MediaFile, Paged, RecordLock, RecordRef, RecordSchedule } from '~/types/api'

// Another user is editing the record right now
const lockedByOther = (record: ContentRecord) => !!record._lock && !(record._lock as RecordLock).mine
// Scheduled publishing: the next thing that happens
const scheduleLabel = (schedule: RecordSchedule) => schedule.publish_at
  ? t('record.publishesAt', { date: format.moment(schedule.publish_at) })
  : t('record.unpublishesAt', { date: format.moment(schedule.unpublish_at!) })

const route = useRoute()
const router = useRouter()
const slug = route.params.slug as string
const { can, isAdmin } = useAuth()
const format = useFormat()
const toast = useToast()
const { t } = useI18n()

const { data: entityData, refresh: refreshEntity } = await useAsyncData(`entity-${slug}`, () => useApi()<{ data: Entity }>(`/entities/${slug}`))
// Fields limited to roles the user may not see are no columns, filters or sort orders
const entity = computed(() => {
  const data = entityData.value?.data
  return data ? { ...data, fields: data.fields.filter(field => field.can_read !== false) } : data
})

// My view of this list: columns and entries per page, saved for me on the server (all browsers)
interface ListPrefs { columns: string[] | null, limit: number }
const PAGE_SIZES = [10, 25, 50, 100, 200]
const prefKey = `list:${entityData.value?.data.id ?? slug}`
const { data: prefData } = await useAsyncData(`pref-${prefKey}`, () => useApi()<{ data: { value: Partial<ListPrefs> | null } }>(`/auth/preferences/${prefKey}`))
const prefs = reactive<ListPrefs>({
  columns: prefData.value?.data.value?.columns ?? null,
  limit: PAGE_SIZES.includes(Number(prefData.value?.data.value?.limit)) ? Number(prefData.value?.data.value?.limit) : 25
})
watchDebounced(() => [prefs.columns, prefs.limit], () => {
  const value = prefs.columns === null && prefs.limit === 25 ? null : { columns: prefs.columns, limit: prefs.limit }
  useApi()(`/auth/preferences/${prefKey}`, { method: 'PUT', body: { value } }).catch(() => {})
}, { debounce: 500, deep: true })
useHead({ title: () => entity.value?.name ?? slug })

// Filters from links like "used in" (?filter[country]=<id>) stay in the URL
const filter = computed(() => Object.fromEntries(
  Object.entries(route.query)
    .filter(([key]) => key.startsWith('filter['))
    .map(([key, value]) => [key.slice(7, -1), String(value)])
))
// Drafts: all records, only drafts or only published ones (filter[draft])
const draftFilter = computed({
  get: () => (filter.value.draft === '1' ? 'draft' : filter.value.draft === '0' ? 'published' : 'all'),
  set: (value: string) => {
    router.replace({ query: { ...route.query, 'page': undefined, 'filter[draft]': value === 'draft' ? '1' : value === 'published' ? '0' : undefined } })
  }
})
const search = ref(String(route.query.s ?? ''))
// Entities with an order field are listed in that order (like the API without ?sort)
const sort = ref(String(route.query.sort ?? entityData.value?.data.order_field ?? '-created_at'))
const page = ref(Number(route.query.page ?? 1))
const limit = computed(() => prefs.limit)
watch(limit, () => { page.value = 1 })
// Trash: deleted records of entities with a trash (only for users who may delete)
const trashMode = ref(route.query.trash === '1')
const canDelete = computed(() => can('delete', slug))
// Tree entities: roots page by page, children loaded when a node is opened. Searching or
// filtering shows a flat list - hits without their parents make no tree.
const view = ref<'tree' | 'list'>(route.query.view === 'list' ? 'list' : 'tree')
const treeField = computed(() => entity.value?.tree_field ?? null)
const treeMode = computed(() => !!treeField.value && view.value === 'tree' && !trashMode.value && !search.value && !Object.keys(filter.value).length)

const query = computed(() => ({
  trash: trashMode.value ? '1' : undefined,
  view: treeField.value && view.value === 'list' ? 'list' : undefined,
  s: search.value || undefined,
  sort: sort.value,
  page: page.value,
  limit: limit.value,
  ...Object.fromEntries(Object.entries(filter.value).map(([field, value]) => [`filter[${field}]`, value])),
  ...(treeMode.value ? { [`filter[${treeField.value}][null]`]: 'true' } : {})
}))

// The key holds the whole query: Nuxt shares data by key, so a key per entity alone could hand a
// new list (e.g. opened via a "used in" link with a filter) the unfiltered rows of the last one.
const { data: fetched, pending, refresh } = await useAsyncData(
  computed(() => `records-${slug}:${JSON.stringify(query.value)}`),
  () => useApi()<Paged<ContentRecord>>(`/entities/${slug}/records`, { query: query.value })
)
// The rows shown so far stay while the next page or filter loads (no empty table in between)
const data = shallowRef(fetched.value)
watch(fetched, (value) => { if (value) data.value = value })

watchDebounced(search, () => { page.value = 1 }, { debounce: 300 })
watch(query, (value) => {
  router.replace({ query: { ...Object.fromEntries(Object.entries(value).filter(([key, v]) => v !== undefined && key !== 'limit' && !(treeMode.value && key === `filter[${treeField.value}][null]`))) } })
})

const CHILD_LIMIT = 200
const children = ref<Record<string, { records: ContentRecord[], total: number }>>({})
const expanded = ref<string[]>([])
const loadingChildren = ref<string[]>([])

async function loadChildren(id: string) {
  loadingChildren.value = [...loadingChildren.value, id]
  try {
    const res = await useApi()<Paged<ContentRecord>>(`/entities/${slug}/records`, { query: { [`filter[${treeField.value}]`]: id, sort: sort.value, limit: CHILD_LIMIT } })
    children.value = { ...children.value, [id]: { records: res.data, total: res.meta.total_items } }
  } finally {
    loadingChildren.value = loadingChildren.value.filter(other => other !== id)
  }
}
async function toggleExpand(record: ContentRecord) {
  if (expanded.value.includes(record.id)) {
    expanded.value = expanded.value.filter(id => id !== record.id)
    return
  }
  if (!children.value[record.id]) await loadChildren(record.id)
  expanded.value = [...expanded.value, record.id]
}
// After changes and when the order changes: reload what is open
async function reloadTree() {
  children.value = {}
  await Promise.all(expanded.value.map(loadChildren))
}
watch(sort, () => { if (treeMode.value) reloadTree() })

// Rows in display order with their depth (the flat list has depth 0)
const rows = computed(() => {
  const result: { record: ContentRecord, depth: number, more: number }[] = []
  const walk = (records: ContentRecord[], depth: number) => {
    for (const record of records) {
      const loaded = children.value[record.id]
      const open = treeMode.value && expanded.value.includes(record.id) && loaded
      result.push({ record, depth, more: open ? loaded.total - loaded.records.length : 0 })
      if (open) walk(loaded.records, depth + 1)
    }
  }
  walk(data.value?.data ?? [], 0)
  return result
})

// Columns: the display field first, then the fields in schema order, then system columns.
// Without own choice: up to 6 fields (no long texts) and "Created".
const SYSTEM_COLUMNS = computed(() => ['created_at', 'updated_at', 'created_by', 'updated_by'].map(name => ({ name, label: t(`records.columns.${name}`) })))
const orderedFields = computed<Field[]>(() => {
  const fields = entity.value?.fields ?? []
  const label = fields.find(f => f.name === entity.value?.label_field)
  return [...(label ? [label] : []), ...fields.filter(f => f !== label)]
})
const defaultColumns = computed(() => [...orderedFields.value.filter(f => f.type !== 'text' && f.type !== 'markdown').slice(0, 6).map(f => f.name), 'created_at'])
const visible = computed(() => new Set(prefs.columns ?? defaultColumns.value))
const columns = computed<Field[]>(() => {
  const chosen = orderedFields.value.filter(f => visible.value.has(f.name))
  // At least one field, otherwise rows cannot be told apart
  return chosen.length ? chosen : orderedFields.value.slice(0, 1)
})
const systemColumns = computed(() => SYSTEM_COLUMNS.value.filter(c => visible.value.has(c.name)))
const columnOptions = computed(() => [...orderedFields.value.map(f => ({ name: f.name, label: f.label })), ...SYSTEM_COLUMNS.value])
function toggleColumn(name: string, value: boolean) {
  const next = new Set(visible.value)
  if (value) next.add(name)
  else next.delete(name)
  prefs.columns = columnOptions.value.map(c => c.name).filter(n => next.has(n))
}

function toggleSort(name: string) {
  sort.value = sort.value === name ? `-${name}` : name
}
function sortIcon(name: string) {
  if (sort.value === name) return 'i-lucide-arrow-up'
  if (sort.value === `-${name}`) return 'i-lucide-arrow-down'
  return 'i-lucide-arrow-up-down'
}
function clearFilter() {
  router.replace({ query: { s: search.value || undefined } })
}
// Selection across pages - cleared when switching between list and trash
const selected = ref<string[]>([])
const busy = ref(false)
const pageIds = computed(() => rows.value.map(row => row.record.id))
const allSelected = computed(() => pageIds.value.length > 0 && pageIds.value.every(id => selected.value.includes(id)))
watch(trashMode, () => {
  page.value = 1
  selected.value = []
})
function toggle(id: string, value: boolean | 'indeterminate') {
  selected.value = value === true ? [...selected.value, id] : selected.value.filter(other => other !== id)
}
function toggleAll(value: boolean | 'indeterminate') {
  selected.value = value === true ? [...new Set([...selected.value, ...pageIds.value])] : selected.value.filter(id => !pageIds.value.includes(id))
}
function openRecord(record: ContentRecord) {
  if (trashMode.value) toggle(record.id, !selected.value.includes(record.id))
  else navigateTo(`/entities/${slug}/${record.id}`)
}

const plural = (count: number) => t('records.count', { count: format.number(count) }, count)
const deleteQuestion = computed(() => entity.value?.trash
  ? t('records.trashQuestion', { records: plural(selected.value.length) })
  : t('records.deleteQuestion', { records: plural(selected.value.length) }))

async function run(action: () => Promise<void>) {
  busy.value = true
  try {
    await action()
  } catch (error) {
    toast.add({ title: apiErrorMessage(error), color: 'error', icon: 'i-lucide-circle-alert' })
  } finally {
    busy.value = false
    await Promise.all([refresh(), refreshEntity(), treeMode.value ? reloadTree() : Promise.resolve()])
  }
}

// Records in use by others are skipped - they stay selected and are listed in the message
function report(result: DeleteResult) {
  if (result.trashed) toast.add({ title: t('records.trashed', { records: plural(result.trashed) }), color: 'success', icon: 'i-lucide-trash-2' })
  if (result.deleted) toast.add({ title: t('records.deleted', { records: plural(result.deleted) }), color: 'success', icon: 'i-lucide-check' })
  if (result.failed.length) {
    toast.add({
      title: t('records.notDeleted', { records: plural(result.failed.length) }),
      description: result.failed.slice(0, 5).map(f => `${f.label}: ${f.message}`).join(' · ') + (result.failed.length > 5 ? ' …' : ''),
      color: 'warning',
      icon: 'i-lucide-triangle-alert',
      duration: 10000
    })
  }
  selected.value = result.failed.map(f => f.id)
}

// Drag & drop: entities with an order field, sorted by it, for users who may edit. A record takes
// the place of the one it is dropped on; in a tree only among records with the same parent.
const sortable = computed(() => !!entity.value?.order_field && sort.value === entity.value.order_field && !trashMode.value && can('update', slug))
const dragging = ref<string | null>(null)
const dropTarget = ref<{ id: string, after: boolean } | null>(null)
const parentOf = (record: ContentRecord) => (treeMode.value && treeField.value ? record[treeField.value] ?? null : null)
const siblingsOf = (record: ContentRecord) => rows.value.map(row => row.record).filter(other => parentOf(other) === parentOf(record)).map(other => other.id)
function dragStart(record: ContentRecord, event: DragEvent) {
  dragging.value = record.id
  event.dataTransfer?.setData('text/plain', record.id)
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}
function dragOver(record: ContentRecord, event: DragEvent) {
  const source = rows.value.find(row => row.record.id === dragging.value)?.record
  if (!source || source.id === record.id || parentOf(source) !== parentOf(record)) return
  event.preventDefault()
  const siblings = siblingsOf(record)
  dropTarget.value = { id: record.id, after: siblings.indexOf(source.id) < siblings.indexOf(record.id) }
}
function dragEnd() {
  dragging.value = null
  dropTarget.value = null
}
function drop(record: ContentRecord) {
  const id = dragging.value
  dragEnd()
  if (!id || id === record.id) return
  const ids = siblingsOf(record)
  const to = ids.indexOf(record.id)
  ids.splice(ids.indexOf(id), 1)
  ids.splice(to, 0, id)
  return run(async () => {
    await useApi()(`/entities/${slug}/records/order`, { method: 'POST', body: { ids } })
  })
}

// The handle with the arrow keys: one place up or down among its siblings
function moveBy(record: ContentRecord, step: number) {
  const ids = siblingsOf(record)
  const from = ids.indexOf(record.id)
  const to = from + step
  if (from < 0 || to < 0 || to >= ids.length) return
  return run(async () => {
    await useApi()(`/entities/${slug}/records/order`, { method: 'POST', body: { ids: moved(ids, from, to) } })
  })
}

function removeSelected(permanent: boolean) {
  return run(async () => {
    report((await useApi()<{ data: DeleteResult }>(`/entities/${slug}/records/delete`, { method: 'POST', body: { ids: selected.value, permanent } })).data)
  })
}

function restoreSelected() {
  return run(async () => {
    const res = await useApi()<{ data: { restored: number } }>(`/entities/${slug}/records/restore`, { method: 'POST', body: { ids: selected.value } })
    toast.add({ title: t('records.restored', { records: plural(res.data.restored) }), color: 'success', icon: 'i-lucide-undo-2' })
    selected.value = []
  })
}

function emptyTrash() {
  return run(async () => {
    report((await useApi()<{ data: DeleteResult }>(`/entities/${slug}/trash/empty`, { method: 'POST' })).data)
  })
}

// Referenced records of a cell (one, or a list) - linked if the user may read their entity
function refsOf(record: ContentRecord, field: Field): RecordRef[] {
  const refs = record._refs?.[field.name]
  return Array.isArray(refs) ? refs : refs ? [refs] : []
}

// Thumbnails of a media field: the file, or the first three images of a list
function firstImages(value: unknown): MediaFile[] {
  const list = Array.isArray(value) ? value as MediaFile[] : value && typeof value === 'object' ? [value as MediaFile] : []
  return list.filter(file => file.is_image).slice(0, 3)
}

// filter[country][name] → "Land › name"
const filterLabel = computed(() => Object.keys(filter.value).map((key) => {
  const [name = key, ...path] = key.split('][')
  return [entity.value?.fields.find(f => f.name === name)?.label ?? name, ...path].join(' › ')
}).join(', '))
</script>

<template>
  <div v-if="entity">
    <AppPageHeader :title="entity.name" :subtitle="entity.description ?? undefined">
      <template #actions>
        <USelect v-if="entity.drafts && !trashMode" v-model="draftFilter" :items="[{ value: 'all', label: $t('records.allStates') }, { value: 'draft', label: $t('records.onlyDrafts') }, { value: 'published', label: $t('records.onlyPublished') }]" class="w-44" />
        <UFieldGroup v-if="treeField && !trashMode">
          <UButton icon="i-lucide-list-tree" :color="view === 'tree' ? 'primary' : 'neutral'" :variant="view === 'tree' ? 'subtle' : 'outline'" :label="$t('records.tree')" @click="view = 'tree'" />
          <UButton icon="i-lucide-list" :color="view === 'list' ? 'primary' : 'neutral'" :variant="view === 'list' ? 'subtle' : 'outline'" :label="$t('records.list')" @click="view = 'list'" />
        </UFieldGroup>
        <UButton
          v-if="entity.trash && canDelete"
          :icon="trashMode ? 'i-lucide-arrow-left' : 'i-lucide-trash'"
          color="neutral"
          variant="outline"
          :label="trashMode ? $t('records.backToList') : `${$t('trash.title')}${entity.trash_count ? ` (${format.number(entity.trash_count)})` : ''}`"
          @click="trashMode = !trashMode"
        />
        <UButton v-if="isAdmin" :to="`/admin/schema/${entity.id}`" icon="i-lucide-settings-2" color="neutral" variant="outline" :label="$t('nav.schema')" />
        <UButton v-if="can('import', slug)" :to="{ path: '/import', query: { target: slug } }" icon="i-lucide-file-up" color="neutral" variant="outline" :label="$t('permissions.import')" />
        <UButton v-if="can('create', slug)" :to="`/entities/${slug}/new`" icon="i-lucide-plus" :label="$t('records.new')" />
      </template>
    </AppPageHeader>

    <div class="flex flex-wrap items-center gap-3 mb-4">
      <UInput v-model="search" icon="i-lucide-search" :placeholder="$t('common.search')" class="max-w-sm" />
      <UBadge v-if="Object.keys(filter).length" color="primary" variant="subtle" size="lg" class="gap-2">
        {{ $t('records.filteredBy', { fields: filterLabel }) }}
        <UButton icon="i-lucide-x" size="xs" color="primary" variant="link" :aria-label="$t('records.removeFilter')" @click="clearFilter" />
      </UBadge>
      <div class="ms-auto flex flex-wrap items-center gap-3">
        <span class="text-sm text-muted">{{ trashMode ? $t('records.inTrash', { count: format.number(data?.meta.total_items ?? 0) }) : treeMode ? $t('records.topLevel', { count: format.number(data?.meta.total_items ?? 0) }) : plural(data?.meta.total_items ?? 0) }}</span>
        <USelect :model-value="prefs.limit" :items="PAGE_SIZES.map(n => ({ value: n, label: $t('records.perPage', { n }) }))" class="w-36" :aria-label="$t('records.perPageLabel')" @update:model-value="prefs.limit = Number($event)" />
        <UPopover>
          <UButton icon="i-lucide-columns-3" color="neutral" variant="outline" :label="$t('records.columnsButton')" />
          <template #content>
            <div class="p-3 space-y-2 max-h-96 overflow-y-auto min-w-56">
              <UCheckbox
                v-for="option in columnOptions"
                :id="`column-${option.name}`"
                :key="option.name"
                :model-value="visible.has(option.name)"
                :label="option.label"
                @update:model-value="toggleColumn(option.name, !!$event)"
              />
              <div class="pt-2 border-t border-default">
                <UButton size="xs" color="neutral" variant="link" :label="$t('records.resetColumns')" :disabled="prefs.columns === null" @click="prefs.columns = null" />
              </div>
            </div>
          </template>
        </UPopover>
      </div>
    </div>

    <UAlert v-if="trashMode" color="neutral" variant="subtle" icon="i-lucide-trash" :title="$t('trash.title')" :description="$t('trash.help')" class="mb-4">
      <template v-if="data?.meta.total_items" #actions>
        <ConfirmButton :label="$t('trash.empty')" icon="i-lucide-trash-2" size="sm" :question="$t('trash.emptyQuestion', { records: plural(data.meta.total_items) })" :confirm-label="$t('records.deleteForGood')" @confirm="emptyTrash" />
      </template>
    </UAlert>

    <div v-if="selected.length" class="mb-4 flex flex-wrap items-center gap-3 rounded-md border border-default bg-elevated/50 px-4 py-2">
      <span class="text-sm font-medium">{{ $t('records.selected', { records: plural(selected.length) }) }}</span>
      <UButton size="sm" color="neutral" variant="link" :label="$t('records.clearSelection')" @click="selected = []" />
      <div class="ms-auto flex gap-2">
        <UButton v-if="!trashMode && selected.length === 1 && can('create', slug)" size="sm" icon="i-lucide-copy" color="neutral" variant="outline" :label="$t('record.duplicate')" :to="{ path: `/entities/${slug}/new`, query: { copy: selected[0] } }" />
        <template v-if="trashMode">
          <UButton size="sm" icon="i-lucide-undo-2" color="neutral" variant="outline" :label="$t('records.restore')" :loading="busy" @click="restoreSelected" />
          <ConfirmButton size="sm" icon="i-lucide-trash-2" :label="$t('records.deleteForGood')" :question="$t('records.deleteQuestion', { records: plural(selected.length) })" @confirm="removeSelected(true)" />
        </template>
        <ConfirmButton
          v-else
          size="sm"
          icon="i-lucide-trash-2"
          :label="entity.trash ? $t('records.toTrash') : $t('common.delete')"
          :question="deleteQuestion"
          :confirm-label="entity.trash ? $t('records.toTrash') : $t('records.deleteForGood')"
          @confirm="removeSelected(false)"
        />
      </div>
    </div>

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-elevated/50 text-left">
            <tr>
              <th v-if="sortable" class="w-8 ps-3" :aria-label="$t('records.dragToSort')" />
              <th v-if="canDelete" class="w-10 px-4 py-3">
                <UCheckbox :model-value="allSelected" :aria-label="$t('records.selectPage')" @update:model-value="toggleAll" />
              </th>
              <th v-for="field in columns" :key="field.name" class="px-4 py-3 font-semibold whitespace-nowrap">
                <button type="button" class="inline-flex items-center gap-1 hover:text-primary" @click="toggleSort(field.name)">
                  {{ field.label }}
                  <UIcon :name="sortIcon(field.name)" class="size-3.5" :class="sort.replace('-', '') === field.name ? 'text-primary' : 'text-dimmed'" />
                </button>
              </th>
              <th v-for="column in systemColumns" :key="column.name" class="px-4 py-3 font-semibold whitespace-nowrap">
                <button type="button" class="inline-flex items-center gap-1 hover:text-primary" @click="toggleSort(column.name)">
                  {{ column.label }} <UIcon :name="sortIcon(column.name)" class="size-3.5" :class="sort.replace('-', '') === column.name ? 'text-primary' : 'text-dimmed'" />
                </button>
              </th>
              <th v-if="trashMode" class="px-4 py-3 font-semibold whitespace-nowrap">{{ $t('records.deletedColumn') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-default" :class="{ 'opacity-60': pending }">
            <tr
              v-for="{ record, depth, more } in rows"
              :key="record.id"
              class="hover:bg-elevated/40 cursor-pointer"
              :class="{
                'bg-primary/5': selected.includes(record.id),
                // Someone else is editing it: faded with stripes; working copy: a bar in the info color
                'locked-row': lockedByOther(record),
                'bg-info/5 shadow-[inset_3px_0_0_var(--ui-info)]': !!record._working_copy && !lockedByOther(record) && dropTarget?.id !== record.id,
                'opacity-40': dragging === record.id,
                'shadow-[inset_0_2px_0_var(--ui-primary)]': dropTarget?.id === record.id && !dropTarget.after,
                'shadow-[inset_0_-2px_0_var(--ui-primary)]': dropTarget?.id === record.id && dropTarget.after
              }"
              :draggable="sortable"
              @click="openRecord(record)"
              @dragstart="dragStart(record, $event)"
              @dragover="dragOver(record, $event)"
              @dragleave="dropTarget?.id === record.id && (dropTarget = null)"
              @drop.prevent="drop(record)"
              @dragend="dragEnd"
            >
              <td v-if="sortable" class="w-8 ps-2" @click.stop>
                <DragHandle @move="by => moveBy(record, by)" />
              </td>
              <td v-if="canDelete" class="w-10 px-4 py-3" @click.stop>
                <UCheckbox :model-value="selected.includes(record.id)" :aria-label="$t('records.select')" @update:model-value="toggle(record.id, $event)" />
              </td>
              <td v-for="(field, index) in columns" :key="field.name" class="px-4 py-3 max-w-xs truncate">
                <div v-if="index === 0 && treeMode" class="flex items-center gap-1" :style="{ paddingLeft: `${depth * 1.5}rem` }">
                  <UButton
                    v-if="record._children"
                    :icon="expanded.includes(record.id) ? 'i-lucide-chevron-down' : 'i-lucide-chevron-right'"
                    :loading="loadingChildren.includes(record.id)"
                    size="xs"
                    color="neutral"
                    variant="ghost"
                    :aria-label="expanded.includes(record.id) ? $t('records.collapse') : $t('records.expand', record._children ?? 0)"
                    @click.stop="toggleExpand(record)"
                  />
                  <span v-else class="inline-block size-6 shrink-0" />
                  <span class="truncate" :class="{ 'font-medium': field.name === entity.label_field }">{{ format.value(field, record) }}</span>
                  <UBadge v-if="record.draft" :label="$t('record.draft')" color="warning" variant="subtle" size="sm" />
                  <UBadge v-if="record._working_copy" :label="$t('record.workingCopy')" color="info" variant="subtle" size="sm" icon="i-lucide-file-pen-line" />
                  <UBadge v-if="record._schedule" :label="scheduleLabel(record._schedule)" :color="record._schedule.errors.publish || record._schedule.errors.unpublish ? 'error' : 'neutral'" variant="subtle" size="sm" icon="i-lucide-calendar-clock" />
                  <span v-if="lockedByOther(record)" class="lock-chip"><UIcon name="i-lucide-lock" class="size-3" />{{ record._lock!.user.name }}</span>
                  <UBadge v-if="record._children" :label="String(record._children)" color="neutral" variant="subtle" size="sm" />
                  <span v-if="more" class="text-xs text-muted">({{ $t('records.more', { count: more }) }})</span>
                </div>
                <UBadge v-else-if="field.type === 'boolean'" :color="record[field.name] ? 'success' : 'neutral'" variant="subtle" :label="format.value(field, record)" />
                <div v-else-if="field.type === 'media' && firstImages(record[field.name]).length" class="flex items-center -space-x-3">
                  <img v-for="file in firstImages(record[field.name])" :key="file.id" :src="file.url" :alt="file.name" class="size-10 rounded object-cover bg-elevated ring-2 ring-default">
                  <span v-if="Array.isArray(record[field.name]) && (record[field.name] as MediaFile[]).length > 3" class="ps-4 text-xs text-muted">+{{ (record[field.name] as MediaFile[]).length - 3 }}</span>
                </div>
                <span v-else-if="field.type === 'reference' && refsOf(record, field).length" class="truncate">
                  <template v-for="(ref, i) in refsOf(record, field)" :key="`${ref.id}-${i}`">
                    <span v-if="i">, </span>
                    <NuxtLink v-if="ref.entity && can('read', ref.entity)" :to="`/entities/${ref.entity}/${ref.id}`" class="text-primary hover:underline" @click.stop>{{ ref.label }}</NuxtLink>
                    <span v-else>{{ ref.label }}</span>
                  </template>
                </span>
                <span v-else :class="{ 'font-medium': field.name === entity.label_field }">{{ format.value(field, record) }}<UBadge v-if="index === 0 && record.draft" :label="$t('record.draft')" color="warning" variant="subtle" size="sm" class="ms-2" /><UBadge v-if="index === 0 && record._working_copy" :label="$t('record.workingCopy')" color="info" variant="subtle" size="sm" icon="i-lucide-file-pen-line" class="ms-2" /><UBadge v-if="index === 0 && record._schedule" :label="scheduleLabel(record._schedule)" :color="record._schedule.errors.publish || record._schedule.errors.unpublish ? 'error' : 'neutral'" variant="subtle" size="sm" icon="i-lucide-calendar-clock" class="ms-2" /><span v-if="index === 0 && lockedByOther(record)" class="lock-chip ms-2" :title="$t('record.lockedBy', { name: record._lock!.user.name })"><UIcon name="i-lucide-lock" class="size-3" />{{ record._lock!.user.name }}</span></span>
              </td>
              <td v-for="column in systemColumns" :key="column.name" class="px-4 py-3 text-muted whitespace-nowrap">
                <template v-if="column.name === 'created_by' || column.name === 'updated_by'">{{ format.actor(record[column.name] as Actor | null) }}</template>
                <template v-else>{{ format.relative((record[column.name] as string | null) ?? null) }}</template>
              </td>
              <td v-if="trashMode" class="px-4 py-3 text-muted whitespace-nowrap">
                {{ format.relative(record.deleted_at ?? null) }}
                <span v-if="record.deleted_by" class="block text-xs">{{ $t('records.by', { actor: format.actor(record.deleted_by) }) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <EmptyState v-if="!pending && !data?.data.length" :text="search || Object.keys(filter).length ? $t('records.nothingFound') : trashMode ? $t('trash.isEmpty') : $t('records.empty')" />
    </UCard>

    <div v-if="(data?.meta.total_pages ?? 0) > 1" class="mt-4 flex justify-center">
      <UPagination v-model:page="page" :total="data?.meta.total_items ?? 0" :items-per-page="limit" />
    </div>
  </div>
</template>

<style scoped>
/* Records someone else is editing: faded, with diagonal stripes in the warning color */
.locked-row > td {
  opacity: 0.65;
}
.locked-row {
  background-image: repeating-linear-gradient(135deg, transparent 0 10px, color-mix(in oklab, var(--ui-warning) 8%, transparent) 10px 20px);
}
.lock-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.0625rem 0.5rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--ui-warning);
  background: color-mix(in oklab, var(--ui-warning) 15%, transparent);
  white-space: nowrap;
}
</style>
