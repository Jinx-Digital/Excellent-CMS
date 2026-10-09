<script setup lang="ts">
import type { Block, ContentRecord, Entity, Field, Paged, RecordLock, RecordRef, RecordSchedule, RevisionDetail, RevisionItem } from '~/types/api'

const route = useRoute()
const slug = route.params.slug as string
const id = route.params.id as string
const isNew = id === 'new'
const { can, canTakeOver, session, isAdmin } = useAuth()
const format = useFormat()
const { t } = useI18n()

const entity = (await useApi()<{ data: Entity }>(`/entities/${slug}`)).data
// Fields limited to roles: the ones the user may not see are not part of the form at all
entity.fields = entity.fields.filter(field => field.can_read !== false)
const record = ref<ContentRecord | null>(isNew ? null : (await useApi()<{ data: ContentRecord }>(`/entities/${slug}/records/${id}`)).data)
useHead({ title: isNew ? `Neu: ${entity.name}` : `${entity.name} bearbeiten` })

// Locks: whoever edits a record locks it - others can only read it until it is free again, or an
// admin or editor takes it over. Renewed every 30 s; the API frees locks nobody renews after 2 min.
const lock = ref<RecordLock | null>(record.value?._lock ?? null)
const lockedByOther = computed(() => !!lock.value && !lock.value.mine)
// Taken over by someone else while I was editing: my unsaved changes stay in the form to copy them
const lostLock = ref(false)
// "Only own records": update_own / delete_own allow records the user created
const mine = computed(() => record.value?.created_by?.type === 'user' && record.value.created_by.id === session.value?.user.id)
const mayUpdate = computed(() => can('update', slug) || (can('update_own', slug) && mine.value))
const mayDelete = computed(() => can('delete', slug) || (can('delete_own', slug) && mine.value))
const editable = computed(() => isNew ? can('create', slug) : mayUpdate.value && !lockedByOther.value)
// Duplicate: "Duplizieren" opens this page with ?copy=<id> - the form starts with the values of that
// record and creates a new one when saved. Number, UUID and slug are made anew, the label gets "(Kopie)".
const copyOf = isNew && typeof route.query.copy === 'string'
  ? (await useApi()<{ data: ContentRecord }>(`/entities/${slug}/records/${route.query.copy}`)).data
  : null
const initial = (field: Field, value: unknown) => {
  if (!copyOf) return value
  if (['autoincrement', 'uuid', 'slug', 'order'].includes(field.type)) return null
  return field.name === entity.label_field && typeof value === 'string' && value ? `${value} ${t('record.copySuffix')}` : value
}
// Working copy: changes of a published record saved for later - the form edits them, live stays
// the published version until "Publish"
const workingCopyOf = (saved: ContentRecord | null) => saved && typeof saved._working_copy === 'object' ? saved._working_copy : null
const workingCopy = computed(() => workingCopyOf(record.value))
const start = workingCopyOf(record.value) ?? record.value ?? copyOf
const form = reactive<Record<string, unknown>>(Object.fromEntries(entity.fields.map(f => [f.name, initial(f, start?.[f.name]) ?? (f.type === 'boolean' ? false : null)])))

// Languages: translatable fields have a value per language. The default language lives in the
// field, the others in _i18n (sent as is); fields that are not translatable count for all languages.
const languages = entity.languages ?? []
// Project variables usable as {{name}} in text fields
const { project } = useAuth()
const variableNames = computed(() => (project.value?.variables ?? []).map(v => `{{${v.name}}}`))
const otherLanguages = languages.slice(1)
const translatable = entity.fields.filter(f => f.translatable)
const language = ref(languages[0] ?? '')
const i18n = reactive<Record<string, Record<string, unknown>>>(Object.fromEntries(translatable.map(f => [f.name, Object.fromEntries(otherLanguages.map(code => [code, initial(f, start?._i18n?.[f.name]?.[code]) ?? null]))])))
const isDefaultLanguage = computed(() => language.value === (languages[0] ?? ''))
function model(field: Field) {
  return computed({
    get: () => field.translatable && !isDefaultLanguage.value ? i18n[field.name]![language.value] : form[field.name],
    set: (value: unknown) => {
      if (field.translatable && !isDefaultLanguage.value) i18n[field.name]![language.value] = value
      else form[field.name] = value
    }
  })
}
const models = Object.fromEntries(entity.fields.map(f => [f.name, model(f)]))
const fieldError = (field: Field) => field.translatable && !isDefaultLanguage.value ? errors.value[`_i18n.${field.name}.${language.value}`] : errors.value[field.name]

// Tabs of the form (schema › form designer): each with its fields; errors are counted on the tab,
// and after a failed save the first tab with errors opens. The tab stays chosen per entity.
const formTabs = computed(() => (entity.tabs?.length ? entity.tabs : [{ key: 'main', label: '', fields: entity.fields.map(f => f.name) }])
  .map(tab => ({ ...tab, fields: tab.fields.map(name => entity.fields.find(f => f.name === name)).filter((f): f is Field => !!f) })))
const activeTab = useState(`record-tab-${slug}`, () => formTabs.value[0]?.key ?? 'main')
if (!formTabs.value.some(tab => tab.key === activeTab.value)) activeTab.value = formTabs.value[0]?.key ?? 'main'
const tabErrors = (fields: Field[]) => fields.filter(field => fieldError(field) || Object.keys(errors.value).some(key => key.startsWith(`${field.name}.`))).length
const tabItems = computed(() => formTabs.value.map(tab => ({ value: tab.key, label: tab.label, badge: tabErrors(tab.fields) ? { label: String(tabErrors(tab.fields)), color: 'error' as const, variant: 'solid' as const, size: 'sm' as const } : undefined })))
const languageItems = computed(() => languages.map((code, index) => ({
  label: code.toUpperCase(),
  value: code,
  // Mark languages with errors
  icon: Object.keys(errors.value).some(key => index === 0 ? !key.startsWith('_i18n.') : key.endsWith(`.${code}`)) ? 'i-lucide-circle-alert' : undefined
})))

// New records: slugs follow their source field (e.g. the title) until they are edited by hand -
// translatable slugs in each language from the source in the same language
if (isNew) {
  for (const field of entity.fields.filter(f => f.type === 'slug' && f.slug_source)) {
    const source = entity.fields.find(f => f.name === field.slug_source)
    // A duplicate starts with the slug of its new label ("titel-kopie")
    if (copyOf) {
      form[field.name] = slugify(String(form[field.slug_source!] ?? ''), field.length ?? 255) || null
      if (field.translatable) {
        for (const code of otherLanguages) {
          const value = source?.translatable ? i18n[source.name]![code] : null
          i18n[field.name]![code] = value ? slugify(String(value), field.length ?? 255) : null
        }
      }
    }
    const follow = (get: () => unknown, read: () => unknown, write: (value: string | null) => void) => watch(get, (value, previous) => {
      const current = read() as string | null
      if (!current || current === slugify(String(previous ?? ''), field.length ?? 255)) write(slugify(String(value ?? ''), field.length ?? 255) || null)
    })
    follow(() => form[field.slug_source!], () => form[field.name], (value) => { form[field.name] = value })
    if (field.translatable) {
      for (const code of otherLanguages) {
        follow(
          () => source?.translatable ? i18n[source.name]![code] : form[field.slug_source!],
          () => i18n[field.name]![code],
          (value) => { i18n[field.name]![code] = value }
        )
      }
    }
  }
}

// Trees: "new child" opens this page with ?parent=<id> - the parent field is filled in
const treeField = entity.tree_field
const parentRef = ref<RecordRef | null>(null)
if (isNew && treeField && typeof route.query.parent === 'string') {
  const parent = (await useApi()<{ data: ContentRecord }>(`/entities/${slug}/records/${route.query.parent}`)).data
  form[treeField] = parent.id
  parentRef.value = { id: parent.id, label: String((entity.label_field && parent[entity.label_field]) || parent.id), entity: slug }
}
const { data: childData, refresh: refreshChildren } = await useAsyncData(`children-${slug}-${id}`, () => isNew || !treeField
  ? Promise.resolve(null)
  : useApi()<Paged<ContentRecord>>(`/entities/${slug}/records`, { query: { [`filter[${treeField}]`]: id, sort: entity.label_field ?? 'created_at', limit: 50 } }))
const { submit, saving, errors } = useSubmit()
// Errors in another tab: open it
watch(errors, () => {
  if (formTabs.value.length < 2 || tabErrors(formTabs.value.find(tab => tab.key === activeTab.value)?.fields ?? [])) return
  const first = formTabs.value.find(tab => tabErrors(tab.fields))
  if (first) activeTab.value = first.key
})

const { data: references } = await useAsyncData(`refs-${slug}-${id}`, () => isNew
  ? Promise.resolve({ data: [] })
  : useApi()<{ data: { entity: string, entity_name: string, field: string, field_label: string, count: number }[] }>(`/entities/${slug}/records/${id}/references`))

const payload = () => translatable.length && otherLanguages.length ? { ...form, _i18n: i18n } : form

// Drafts: saved as draft (admin app only) or published (content API too)
const isDraft = computed(() => !!record.value?.draft)
const withDraft = (draft?: boolean) => entity.drafts && draft !== undefined ? { ...payload(), draft } : payload()
// Published records (without drafts: all of them) can be changed in a working copy first
const usesWorkingCopy = computed(() => !isNew && !isDraft.value)
// Saving makes it live: "Publish" in entities with drafts, "Save" otherwise
const liveLabel = computed(() => entity.drafts ? t('record.publish') : t('common.save'))
// What Enter and Cmd/Ctrl+S do: keep the state - new records and drafts stay drafts, published
// records save their working copy
function saveKeepingState() {
  return usesWorkingCopy.value ? saveWorkingCopy() : save(entity.drafts ? isDraft.value || isNew : undefined)
}
async function saveWorkingCopy() {
  const saved = await submit(() => useApi()<{ data: ContentRecord }>(`/entities/${slug}/records/${id}/working-copy`, { method: 'PUT', body: payload() }), t('record.workingCopySaved'))
  if (saved) fill(saved.data)
}
async function publish() {
  const saved = await submit(() => useApi()<{ data: ContentRecord }>(`/entities/${slug}/records/${id}/working-copy/publish`, { method: 'POST', body: payload() }), t('record.publishedNow'))
  if (saved) {
    fill(saved.data)
    await Promise.all([refreshChildren(), refreshRevisions()])
  }
}
async function discardWorkingCopy() {
  const saved = await submit(() => useApi()<{ data: ContentRecord }>(`/entities/${slug}/records/${id}/working-copy`, { method: 'DELETE' }), t('record.workingCopyDiscarded'))
  if (saved) fill(saved.data)
}
async function save(draft?: boolean) {
  const saved = await submit(() => isNew
    ? useApi()<{ data: ContentRecord }>(`/entities/${slug}/records`, { method: 'POST', body: withDraft(draft) })
    : useApi()<{ data: ContentRecord }>(`/entities/${slug}/records/${id}`, { method: 'PUT', body: withDraft(draft) }), isNew ? (draft ? t('record.draftSaved') : t('record.created')) : undefined)
  if (saved) {
    snapshot.value = JSON.stringify(payload())
    if (isNew) return navigateTo(`/entities/${slug}/${saved.data.id}`, { replace: true })
    // Back to draft discards the working copy: the form shows what is saved now
    if (workingCopy.value && saved.data.draft) fill(saved.data)
    else record.value = saved.data
    await Promise.all([refreshChildren(), refreshRevisions()])
  }
}

async function remove() {
  if (await submit(() => useApi()(`/entities/${slug}/records/${id}`, { method: 'DELETE' }), entity.trash ? t('record.trashed') : t('record.deleted')) !== null) {
    await navigateTo(`/entities/${slug}`)
  }
}

// Revisions: history of the record, compare a version with now, restore all fields or some
const { data: revisionData, refresh: refreshRevisions } = await useAsyncData(`revisions-${slug}-${id}`, () => isNew || !entity.revisions
  ? Promise.resolve({ data: [] as RevisionItem[] })
  : useApi()<{ data: RevisionItem[] }>(`/entities/${slug}/records/${id}/revisions`))
const revisions = computed(() => revisionData.value?.data ?? [])
const revision = ref<RevisionDetail | null>(null)
const revisionOpen = ref(false)
const restoreFields = ref<string[]>([])
const restorable = (field: Field) => !['autoincrement', 'order'].includes(field.type)
const sameValue = (field: Field, a: ContentRecord, b: ContentRecord) =>
  JSON.stringify([a[field.name] ?? null, a._i18n?.[field.name] ?? null]) === JSON.stringify([b[field.name] ?? null, b._i18n?.[field.name] ?? null])
const differences = computed(() => revision.value && record.value
  ? entity.fields.filter(f => !sameValue(f, revision.value!.record, record.value!)).map(f => f.name)
  : [])
async function openRevision(item: RevisionItem) {
  revision.value = (await useApi()<{ data: RevisionDetail }>(`/entities/${slug}/records/${id}/revisions/${item.id}`)).data
  restoreFields.value = differences.value.filter(name => restorable(entity.fields.find(f => f.name === name)!))
  revisionOpen.value = true
}
// The form starts from the saved record (or its working copy) - after a restore it takes the restored values
function fill(saved: ContentRecord) {
  record.value = saved
  const values = workingCopyOf(saved) ?? saved
  for (const field of entity.fields) form[field.name] = values[field.name] ?? (field.type === 'boolean' ? false : null)
  for (const field of translatable) for (const code of otherLanguages) i18n[field.name]![code] = values._i18n?.[field.name]?.[code] ?? null
  snapshot.value = JSON.stringify(payload())
}
async function restoreRevision(fields: string[] | null) {
  const res = await submit(() => useApi()<{ data: ContentRecord }>(`/entities/${slug}/records/${id}/revisions/${revision.value!.id}/restore`, { method: 'POST', body: fields ? { fields } : {} }), t('revisions.restored'))
  if (res) {
    fill(res.data)
    revisionOpen.value = false
    await refreshRevisions()
  }
}

// Unsaved changes: shown in the sidebar, asked about before leaving the page
const snapshot = ref(JSON.stringify(payload()))
const dirty = computed(() => editable.value && JSON.stringify(payload()) !== snapshot.value)
// Leaving with unsaved changes asks in a dialog (closing the tab can only use the browser's question)
const leaveOpen = ref(false)
let leaveTo: string | null = null
let leaving = false
onBeforeRouteLeave((to) => {
  if (leaving || !dirty.value) return true
  leaveTo = to.fullPath
  leaveOpen.value = true
  return false
})
async function leave(saveFirst: boolean) {
  if (saveFirst) {
    await saveKeepingState()
    // Not saved (errors in the form): stay
    if (dirty.value) {
      leaveOpen.value = false
      return
    }
  }
  leaving = true
  leaveOpen.value = false
  if (leaveTo) await navigateTo(leaveTo)
}
useEventListener('beforeunload', (event: BeforeUnloadEvent) => {
  if (dirty.value) event.preventDefault()
})
useEventListener('keydown', (event: KeyboardEvent) => {
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
    event.preventDefault()
    if (editable.value && !saving.value) saveKeepingState()
  }
})

// Preview on the website: next to the form or in a new tab - only entities with a preview address
// (schema settings), e.g. pages - not forms or messages
const hasPreview = computed(() => !isNew && !!entity.preview_url)
const previewOpen = usePreviewOpen()
onBeforeUnmount(() => { previewOpen.value = false })
const previewPanel = ref<{ newTab: () => Promise<void>, highlight: (key: string | null, scroll?: boolean) => void } | null>(null)
// Large screens: form and sidebar side by side - with the preview open everything is one column
// next to it (saving in the bar at the bottom, like on small screens)
const wide = computed(() => previewOpen.value
  ? { grid: '', aside: '', onlySmall: '', onlyLarge: 'hidden' }
  : { grid: 'xl:grid-cols-[minmax(0,1fr)_19rem]', aside: 'xl:sticky xl:top-8 xl:max-h-[calc(100svh-4rem)] xl:overflow-y-auto xl:pe-1', onlySmall: 'xl:hidden', onlyLarge: 'hidden xl:block' })
// Live editing: a block clicked in the preview opens its fields in a panel next to it - every change
// goes to the preview at once (as unsaved values), saving works as always. Blocks are found at any
// depth (nested blocks, see blockTree); "+" in the preview adds one (type picker), the bin removes one.
const liveEdit = ref<{ field: string, key: string } | null>(null)
const picker = ref<{ field: string, key: string | null, position: 'before' | 'after' } | null>(null)
const blocksFields = entity.fields.filter(f => f.blocks?.length)
const rootField = (name: string | null) => blocksFields.find(f => name === null || f.name === name) ?? null
const liveField = computed(() => liveEdit.value ? rootField(liveEdit.value.field) : null)
const liveValue = computed(() => liveField.value ? models[liveField.value.name]!.value : null)
const livePath = computed(() => liveEdit.value && liveValue.value ? findBlockPath(liveValue.value, liveEdit.value.key) : null)
const liveBlock = computed(() => livePath.value ? getAt(liveValue.value, livePath.value) as Block : null)
// The list the block is in, and the blocks field of that list (the root field or a nested one)
const liveList = computed(() => livePath.value ? (getAt(liveValue.value, livePath.value.slice(0, -1)) as Block[]) : [])
const liveIndex = computed(() => livePath.value ? livePath.value[livePath.value.length - 1] as number : -1)
const liveListField = computed(() => liveField.value && livePath.value ? blocksFieldAt(liveField.value, liveValue.value, livePath.value) : null)
const liveGroup = computed(() => liveListField.value?.blocks?.find(group => group.name === liveBlock.value?._type) ?? null)
const liveGroupField = computed<Field | null>(() => liveListField.value && liveGroup.value ? { ...liveListField.value, repeatable: false, required: false, blocks: null, group: liveGroup.value } : null)
const writeTree = (field: Field, next: unknown) => { models[field.name]!.value = next }

function selectBlock({ field, key }: { field: string | null, key: string }) {
  const target = rootField(field)
  if (!target) return
  picker.value = null
  liveEdit.value = { field: target.name, key }
}
function updateLiveBlock(value: Record<string, unknown> | null | undefined) {
  const block = liveBlock.value
  if (!block || !liveField.value || !livePath.value) return
  writeTree(liveField.value, setAt(liveValue.value, livePath.value, { ...(value ?? {}), _type: block._type, _key: block._key }))
}
function moveLiveBlock(step: number) {
  if (!liveField.value || !livePath.value) return
  const to = liveIndex.value + step
  const list = [...liveList.value]
  if (to < 0 || to >= list.length) return
  const [block] = list.splice(liveIndex.value, 1)
  list.splice(to, 0, block!)
  writeTree(liveField.value, setAt(liveValue.value, livePath.value.slice(0, -1), list))
  nextTick(() => previewPanel.value?.highlight(block!._key, true))
}

// Adding: the picker shows the block types of the list at that place
const pickerTypes = computed(() => {
  if (!picker.value) return []
  const field = rootField(picker.value.field)
  if (!field) return []
  if (!picker.value.key) return field.blocks ?? []
  const value = models[field.name]!.value
  const path = findBlockPath(value, picker.value.key)
  return path ? blocksFieldAt(field, value, path)?.blocks ?? [] : []
})
// By category, filtered by the search (label, name, category)
const pickerSearch = ref('')
watch(picker, () => { pickerSearch.value = '' })
const pickerSections = computed(() => {
  const q = pickerSearch.value.trim().toLowerCase()
  return byCategory(pickerTypes.value.filter(g => !q || [g.label, g.name, g.category ?? ''].some(text => text.toLowerCase().includes(q))))
})
function openPicker({ field, key, position }: { field: string | null, key: string | null, position: 'before' | 'after' }) {
  const target = rootField(field)
  if (!target || !editable.value) return
  liveEdit.value = null
  picker.value = { field: target.name, key, position }
}
function insertBlock(group: { name: string, fields: Field[] }) {
  const place = picker.value
  const field = place ? rootField(place.field) : null
  if (!place || !field) return
  const value = models[field.name]!.value
  const block = newBlock(group)
  const path = place.key ? findBlockPath(value, place.key) : null
  if (path) {
    const list = [...(getAt(value, path.slice(0, -1)) as Block[])]
    list.splice((path[path.length - 1] as number) + (place.position === 'after' ? 1 : 0), 0, block)
    writeTree(field, setAt(value, path.slice(0, -1), list))
  } else {
    writeTree(field, [...(Array.isArray(value) ? value as Block[] : []), block])
  }
  picker.value = null
  liveEdit.value = { field: field.name, key: block._key }
  // The page renders the new block a moment later
  setTimeout(() => previewPanel.value?.highlight(block._key, true), 700)
}

// Removing - with "undo" in the toast
function removeBlock({ field, key }: { field: string | null, key: string }) {
  const target = rootField(field)
  if (!target || !editable.value) return
  const before = models[target.name]!.value
  const path = findBlockPath(before, key)
  if (!path) return
  const list = [...(getAt(before, path.slice(0, -1)) as Block[])]
  const [removed] = list.splice(path[path.length - 1] as number, 1)
  writeTree(target, setAt(before, path.slice(0, -1), list))
  if (liveEdit.value?.key === key) liveEdit.value = null
  const group = blocksFieldAt(target, before, path)?.blocks?.find(g => g.name === removed?._type)
  toast.add({
    title: t('preview.blockRemoved', { type: group?.label ?? removed?._type ?? '' }),
    icon: 'i-lucide-trash-2',
    actions: [{ label: t('preview.undo'), icon: 'i-lucide-undo-2', color: 'neutral', variant: 'outline', onClick: () => { writeTree(target, before) } }],
  })
}

// Duplicating: the copy (new keys) right after the block, then selected
function duplicateBlock({ field, key }: { field: string | null, key: string }) {
  const target = rootField(field)
  if (!target || !editable.value) return
  const value = models[target.name]!.value
  const path = findBlockPath(value, key)
  if (!path) return
  const list = [...(getAt(value, path.slice(0, -1)) as Block[])]
  const copy = copyBlock(list[path[path.length - 1] as number]!)
  list.splice((path[path.length - 1] as number) + 1, 0, copy)
  writeTree(target, setAt(value, path.slice(0, -1), list))
  liveEdit.value = { field: target.name, key: copy._key }
  picker.value = null
  toast.add({ title: t('preview.blockDuplicated'), icon: 'i-lucide-copy', color: 'success' })
  setTimeout(() => previewPanel.value?.highlight(copy._key, true), 700)
}

// Dragged in the page: before or after another block - also into another list (e.g. a column),
// if that list allows the type; never into itself
function moveBlock({ field, key, target, targetField, position }: { field: string | null, key: string, target: string, targetField: string | null, position: 'before' | 'after' }) {
  const from = rootField(field)
  const to = rootField(targetField)
  if (!from || !to || !editable.value || key === target) return
  const value = models[from.name]!.value
  const path = findBlockPath(value, key)
  if (!path) return
  const block = getAt(value, path) as Block
  if (findBlockPath(block, target)) return
  const list = [...(getAt(value, path.slice(0, -1)) as Block[])]
  list.splice(path[path.length - 1] as number, 1)
  const without = setAt(value, path.slice(0, -1), list)
  const targetValue = from.name === to.name ? without : models[to.name]!.value
  const targetPath = findBlockPath(targetValue, target)
  if (!targetPath) return
  const allowed = blocksFieldAt(to, targetValue, targetPath)?.blocks?.find(group => group.name === block._type)
  if (!allowed) {
    const group = blocksFieldAt(from, value, path)?.blocks?.find(g => g.name === block._type)
    toast.add({ title: t('preview.moveNotAllowed', { type: group?.label ?? block._type }), icon: 'i-lucide-ban', color: 'warning' })
    return
  }
  const targetList = [...(getAt(targetValue, targetPath.slice(0, -1)) as Block[])]
  targetList.splice((targetPath[targetPath.length - 1] as number) + (position === 'after' ? 1 : 0), 0, block)
  if (from.name !== to.name) writeTree(from, without)
  writeTree(to, setAt(targetValue, targetPath.slice(0, -1), targetList))
  if (liveEdit.value?.key === key) liveEdit.value = { field: to.name, key }
  setTimeout(() => previewPanel.value?.highlight(liveEdit.value?.key ?? null), 700)
}

// Closing the panel (or the preview) ends the selection in the page too
watch(liveEdit, (value) => { if (!value) previewPanel.value?.highlight(null) })
watch(previewOpen, (value) => { if (!value) { liveEdit.value = null; picker.value = null } })

async function previewInNewTab() {
  if (!previewOpen.value) {
    const res = await submit(() => useApi()<{ data: { url: string | null } }>(`/entities/${slug}/records/${id}/preview`, { method: 'POST', body: { lang: language.value || undefined } }), '')
    if (res?.data.url) window.open(res.data.url, '_blank', 'noopener')
    return
  }
  await previewPanel.value?.newTab()
}

// Variables: a click copies the placeholder
const { copy } = useClipboard()
const toast = useToast()
async function copyVariable(name: string) {
  await copy(name)
  toast.add({ title: t('common.copied'), description: name, icon: 'i-lucide-check', color: 'success' })
}

// Working copy against the published version: the fields that differ
const compareOpen = ref(false)
const copyDifferences = computed(() => workingCopy.value && record.value
  ? entity.fields.filter(f => !sameValue(f, workingCopy.value!, record.value!))
  : [])

const status = computed(() => isNew
  ? { label: t('records.new'), color: 'info' as const, icon: 'i-lucide-sparkles' }
  : isDraft.value
    ? { label: t('record.draft'), color: 'warning' as const, icon: 'i-lucide-pencil-line' }
    : { label: t('record.published'), color: 'success' as const, icon: 'i-lucide-circle-check' })

// Schedule (entities with drafts): publish a draft - or the working copy of a published record - and
// unpublish later. The inputs are in the browser's time, the API gets ISO 8601.
const toInput = (value: string | null | undefined) => {
  if (!value) return ''
  const date = new Date(value)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}
const fromInput = (value: string) => value ? new Date(value).toISOString() : null
const scheduleForm = reactive({ publish_at: '', unpublish_at: '' })
const schedule = computed(() => record.value?._schedule ?? null)
watch(schedule, (value) => {
  scheduleForm.publish_at = toInput(value?.publish_at)
  scheduleForm.unpublish_at = toInput(value?.unpublish_at)
}, { immediate: true })
const scheduleChanged = computed(() => scheduleForm.publish_at !== toInput(schedule.value?.publish_at) || scheduleForm.unpublish_at !== toInput(schedule.value?.unpublish_at))
async function saveSchedule() {
  const saved = await submit(() => useApi()<{ data: RecordSchedule | null }>(`/entities/${slug}/records/${id}/schedule`, { method: 'PUT', body: { publish_at: fromInput(scheduleForm.publish_at), unpublish_at: fromInput(scheduleForm.unpublish_at) } }), t('record.scheduleSaved'))
  if (saved && record.value) record.value = { ...record.value, _schedule: saved.data }
}

const lockUrl = `/entities/${slug}/records/${id}/lock`
const usesLock = !isNew && mayUpdate.value
async function reload() {
  fill((await useApi()<{ data: ContentRecord }>(`/entities/${slug}/records/${id}`)).data)
  lostLock.value = false
}
async function renewLock() {
  const before = lock.value
  try {
    lock.value = (await useApi()<{ data: RecordLock }>(lockUrl, { method: 'POST' })).data
  } catch {
    return
  }
  if (before?.mine && !lock.value.mine) lostLock.value = true
  // Free again after someone else edited it: start from what they saved (unless my own changes are still in the form)
  else if (before && !before.mine && lock.value.mine && !lostLock.value) await reload()
}
async function takeOver() {
  const taken = await submit(() => useApi()<{ data: RecordLock }>(`${lockUrl}/take-over`, { method: 'POST' }), t('record.takenOver'))
  if (taken) {
    lock.value = taken.data
    await reload()
  }
}
function releaseLock() {
  if (lock.value?.mine) useApi()(lockUrl, { method: 'DELETE', keepalive: true }).catch(() => {})
}
if (usesLock) {
  onMounted(renewLock)
  useIntervalFn(renewLock, 30_000)
  onUnmounted(releaseLock)
  // Closing the tab: released right away instead of after 2 minutes
  useEventListener('pagehide', releaseLock)
}

const title = computed(() => isNew ? (copyOf ? t('record.duplicateTitle') : t('records.new')) : String((entity.label_field && record.value?.[entity.label_field]) || t('record.record')))
</script>

<template>
  <form :class="previewOpen ? 'mx-auto max-w-4xl' : 'max-w-6xl mx-auto'" @submit.prevent="saveKeepingState()">
    <nav v-if="record?._path?.length" class="mb-2 flex flex-wrap items-center gap-1 text-sm text-muted" :aria-label="$t('record.path')">
      <NuxtLink :to="`/entities/${slug}`" class="hover:text-primary">{{ entity.name }}</NuxtLink>
      <template v-for="node in record._path" :key="node.id">
        <UIcon name="i-lucide-chevron-right" class="size-3.5" />
        <NuxtLink :to="`/entities/${slug}/${node.id}`" class="hover:text-primary">{{ node.label }}</NuxtLink>
      </template>
    </nav>
    <AppPageHeader :title="title" :subtitle="entity.name" :back="`/entities/${slug}`">
      <template #actions>
        <UBadge v-if="entity.drafts || isNew" :label="status.label" :color="status.color" :icon="status.icon" variant="subtle" :class="wide.onlySmall" />
        <UBadge v-if="workingCopy" :label="$t('record.workingCopy')" color="info" icon="i-lucide-file-pen-line" variant="subtle" :class="wide.onlySmall" />
      </template>
    </AppPageHeader>

    <div class="grid gap-6 items-start" :class="wide.grid">
      <!-- Fields -->
      <div class="min-w-0 space-y-6">
        <UAlert
          v-if="lostLock && lockedByOther"
          color="error"
          variant="subtle"
          icon="i-lucide-lock"
          :title="$t('record.lostLockTitle', { name: lock!.user.name })"
          :description="$t('record.lostLockText')"
          :actions="[{ label: $t('record.reload'), icon: 'i-lucide-refresh-cw', color: 'neutral', variant: 'outline', onClick: reload }]"
        />
        <UAlert
          v-else-if="lockedByOther"
          color="warning"
          variant="subtle"
          icon="i-lucide-lock"
          :title="$t('record.lockedTitle', { name: lock!.user.name })"
          :description="$t('record.lockedText', { since: format.relative(lock!.locked_at) })"
          :actions="canTakeOver ? [{ label: $t('record.takeOver'), icon: 'i-lucide-hand', color: 'warning', variant: 'outline', loading: saving, onClick: takeOver }] : []"
        />
        <UAlert
          v-if="workingCopy"
          color="info"
          variant="subtle"
          icon="i-lucide-file-pen-line"
          :title="$t('record.workingCopyTitle')"
          :description="$t('record.workingCopyText', { date: format.relative(workingCopy.updated_at), live: format.relative(record!.updated_at) })"
          :actions="[{ label: $t('record.compareLive'), icon: 'i-lucide-git-compare', color: 'info', variant: 'outline', onClick: () => { compareOpen = true } }]"
        />
        <AppTabs v-if="formTabs.length > 1" v-model="activeTab" :items="tabItems" />
        <!-- Locked by someone else: faded behind stripes; working copy: framed in the info color -->
        <div class="relative">
          <span v-if="lockedByOther" class="absolute -top-3 left-1/2 z-10 -translate-x-1/2 inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-warning px-3 py-1 text-xs font-medium text-inverted shadow">
            <UIcon name="i-lucide-lock" class="size-3.5" />{{ $t('record.lockedBy', { name: lock!.user.name }) }}
          </span>
          <span v-else-if="workingCopy" class="absolute -top-3 right-4 z-10 inline-flex items-center gap-1.5 rounded-full bg-info px-3 py-1 text-xs font-medium text-inverted shadow">
            <UIcon name="i-lucide-file-pen-line" class="size-3.5" />{{ $t('record.workingCopy') }}
          </span>
          <div v-if="lockedByOther" class="locked-stripes pointer-events-none absolute inset-0 z-[5] rounded-lg" />
        <UCard :class="{ 'opacity-60 saturate-50': lockedByOther, 'ring-2 ring-info/50': workingCopy && !lockedByOther }">
          <div v-if="languages.length > 1 && translatable.length" :class="wide.onlySmall" class="mb-5 flex flex-wrap items-center gap-3 border-b border-default pb-4">
            <UTabs v-model="language" :items="languageItems" :content="false" size="sm" variant="pill" />
            <span class="text-sm text-muted">{{ isDefaultLanguage ? $t('projects.defaultLanguage') : $t('record.translationHint') }}</span>
          </div>
          <template v-for="tab in formTabs" :key="tab.key">
            <div v-show="formTabs.length === 1 || activeTab === tab.key" class="space-y-6">
              <UFormField
                v-for="field in tab.fields"
                :key="field.name"
                :label="field.translatable && languages.length > 1 && translatable.length ? `${field.label} (${language.toUpperCase()})` : field.label"
                :required="field.required"
                :hint="typeLabel(field, $t)"
                :help="field.unique && !isGeneratedType(field.type) ? $t('record.mustBeUnique') : undefined"
                :error="fieldError(field)"
                :description="languages.length > 1 && translatable.length && !field.translatable && !isDefaultLanguage ? $t('record.allLanguages') : undefined"
                :ui="{ hint: 'text-xs text-dimmed' }"
              >
                <FieldInput v-model="models[field.name]!.value" :field="field" :entity="slug" :reference="(workingCopy ?? record ?? copyOf)?._refs?.[field.name] ?? (field.name === treeField ? parentRef : null)" :disabled="!editable || field.can_write === false" />
              </UFormField>
              <p v-if="!tab.fields.length" class="text-sm text-muted">{{ $t('formTabs.empty') }}</p>
            </div>
          </template>
        </UCard>
        </div>

        <!-- Small screens: saving stays at hand at the bottom -->
        <div v-if="editable" :class="wide.onlySmall" class="sticky bottom-3 z-10 flex flex-wrap items-center justify-end gap-2 rounded-lg border border-default bg-default/95 p-2 shadow-lg backdrop-blur">
          <span v-if="dirty" class="me-auto ps-1 text-sm text-warning">{{ $t('record.unsaved') }}</span>
          <template v-if="usesWorkingCopy">
            <UButton :loading="saving" icon="i-lucide-file-pen-line" color="neutral" variant="outline" :label="$t('record.saveWorkingCopy')" @click="saveWorkingCopy" />
            <UButton :loading="saving" :icon="entity.drafts ? 'i-lucide-send' : 'i-lucide-save'" :label="liveLabel" @click="publish" />
          </template>
          <template v-else-if="entity.drafts">
            <UButton :loading="saving" icon="i-lucide-pencil-line" color="neutral" variant="outline" :label="$t('record.saveDraft')" @click="save(true)" />
            <UButton :loading="saving" icon="i-lucide-send" :label="$t('record.publish')" @click="save(false)" />
          </template>
          <UButton v-else type="submit" :loading="saving" icon="i-lucide-save" :label="isNew ? $t('common.create') : $t('common.save')" />
        </div>
      </div>

      <!-- Sidebar: language, status and actions, details -->
      <aside class="space-y-4" :class="wide.aside">
        <UCard v-if="languages.length > 1 && translatable.length" :class="wide.onlyLarge" :ui="{ body: 'p-4 sm:p-4' }">
          <p class="mb-2 flex items-center gap-2 text-sm font-semibold"><UIcon name="i-lucide-languages" class="size-4 text-muted" />{{ $t('record.language') }}</p>
          <UTabs v-model="language" :items="languageItems" :content="false" size="sm" variant="pill" class="w-full" />
          <p class="mt-2 text-xs text-muted">{{ isDefaultLanguage ? $t('projects.defaultLanguage') : $t('record.translationHint') }}</p>
        </UCard>

        <UCard :class="wide.onlyLarge" :ui="{ body: 'p-4 sm:p-4 space-y-3' }">
          <div class="flex flex-wrap items-center gap-2">
            <UBadge :label="status.label" :color="status.color" :icon="status.icon" variant="subtle" />
            <UBadge v-if="workingCopy" :label="$t('record.workingCopy')" color="info" icon="i-lucide-file-pen-line" variant="subtle" />
            <span v-if="dirty" class="ms-auto flex items-center gap-1 text-xs text-warning"><span class="size-1.5 rounded-full bg-warning" />{{ $t('record.unsaved') }}</span>
          </div>
          <template v-if="editable">
            <template v-if="usesWorkingCopy">
              <UButton block :loading="saving" :icon="entity.drafts ? 'i-lucide-send' : 'i-lucide-save'" :label="liveLabel" @click="publish" />
              <UButton block :loading="saving" icon="i-lucide-file-pen-line" color="neutral" variant="outline" :label="$t('record.saveWorkingCopy')" @click="saveWorkingCopy" />
              <ConfirmButton v-if="workingCopy" block icon="i-lucide-undo-2" color="neutral" variant="ghost" :label="$t('record.discardWorkingCopy')" :question="$t('record.discardQuestion')" @confirm="discardWorkingCopy" />
              <UButton v-if="entity.drafts" block :loading="saving" icon="i-lucide-pencil-line" color="neutral" variant="ghost" size="sm" :label="$t('record.unpublish')" @click="save(true)" />
            </template>
            <template v-else-if="entity.drafts">
              <UButton block :loading="saving" icon="i-lucide-send" :label="$t('record.publish')" @click="save(false)" />
              <UButton block :loading="saving" icon="i-lucide-pencil-line" color="neutral" variant="outline" :label="$t('record.saveDraft')" @click="save(true)" />
            </template>
            <UButton v-else block type="submit" :loading="saving" icon="i-lucide-save" :label="isNew ? $t('common.create') : $t('common.save')" />
            <p class="text-center text-xs text-dimmed">{{ usesWorkingCopy ? $t('record.shortcutWorkingCopy') : $t('record.shortcut') }}</p>
          </template>
        </UCard>

        <div v-if="hasPreview" class="flex gap-2">
          <UButton block class="flex-1" icon="i-lucide-eye" :color="previewOpen ? 'primary' : 'neutral'" :variant="previewOpen ? 'soft' : 'outline'" :label="$t('preview.open')" @click="previewOpen = !previewOpen" />
          <UButton icon="i-lucide-external-link" color="neutral" variant="outline" :disabled="!entity.preview_url" :aria-label="$t('preview.newTab')" @click="previewInNewTab" />
        </div>

        <div v-if="!isNew && (can('create', slug) || (mayDelete && !lockedByOther))" class="flex gap-2">
          <UButton v-if="can('create', slug)" block class="flex-1" :to="{ path: `/entities/${slug}/new`, query: { copy: id } }" icon="i-lucide-copy" color="neutral" variant="outline" :label="$t('record.duplicate')" />
          <div v-if="mayDelete && !lockedByOther" class="flex-1">
            <ConfirmButton block :label="$t('common.delete')" icon="i-lucide-trash-2" variant="outline" :question="entity.trash ? $t('record.trashQuestion') : $t('record.deleteQuestion')" @confirm="remove" />
          </div>
        </div>

        <UCard v-if="!isNew && record" :ui="{ body: 'p-4 sm:p-4' }">
          <dl class="text-sm space-y-2">
            <div class="flex justify-between gap-4"><dt class="text-muted">ID</dt><dd class="font-mono truncate" :title="record.id">{{ record.id }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-muted">{{ $t('records.columns.created_at') }}</dt><dd class="text-end">{{ format.relative(record.created_at) }}<span v-if="record.created_by" class="block text-xs text-muted">{{ $t('records.by', { actor: format.actor(record.created_by) }) }}</span></dd></div>
            <div class="flex justify-between gap-4"><dt class="text-muted">{{ $t('records.columns.updated_at') }}</dt><dd class="text-end">{{ format.relative(record.updated_at) }}<span v-if="record.updated_by" class="block text-xs text-muted">{{ $t('records.by', { actor: format.actor(record.updated_by) }) }}</span></dd></div>
          </dl>
        </UCard>

        <details v-if="entity.drafts && !isNew && record && (editable || schedule)" class="group rounded-lg border border-default bg-default" :open="!!schedule">
          <summary class="flex cursor-pointer list-none items-center justify-between gap-2 p-4 text-sm font-semibold">
            <span class="flex items-center gap-2"><UIcon name="i-lucide-calendar-clock" class="size-4 text-muted" />{{ $t('record.schedule') }}</span>
            <span class="flex items-center gap-2">
              <UIcon v-if="schedule?.errors.publish || schedule?.errors.unpublish" name="i-lucide-circle-alert" class="size-4 text-error" />
              <UBadge v-else-if="schedule" :label="$t('record.scheduled')" color="info" variant="subtle" size="sm" />
              <UIcon name="i-lucide-chevron-down" class="size-4 text-muted transition-transform group-open:rotate-180" />
            </span>
          </summary>
          <div class="space-y-3 px-4 pb-4">
            <UFormField :label="isDraft ? $t('record.publishAt') : $t('record.publishCopyAt')" :help="isDraft ? $t('record.publishAtHelp') : $t('record.publishCopyAtHelp')" :error="errors.publish_at || schedule?.errors.publish">
              <UInput v-model="scheduleForm.publish_at" type="datetime-local" :disabled="!editable" class="w-full" />
            </UFormField>
            <UFormField :label="$t('record.unpublishAt')" :help="$t('record.unpublishAtHelp')" :error="errors.unpublish_at || schedule?.errors.unpublish">
              <UInput v-model="scheduleForm.unpublish_at" type="datetime-local" :disabled="!editable" class="w-full" />
            </UFormField>
            <UButton v-if="editable" block size="sm" color="neutral" variant="outline" icon="i-lucide-calendar-check" :loading="saving" :disabled="!scheduleChanged && !(schedule?.errors.publish || schedule?.errors.unpublish)" :label="$t('record.saveSchedule')" @click="saveSchedule" />
          </div>
        </details>

        <details v-if="variableNames.length && editable" class="group rounded-lg border border-default bg-default">
          <summary class="flex cursor-pointer list-none items-center justify-between gap-2 p-4 text-sm font-semibold">
            <span class="flex items-center gap-2"><UIcon name="i-lucide-braces" class="size-4 text-muted" />{{ $t('record.variables') }}</span>
            <UIcon name="i-lucide-chevron-down" class="size-4 text-muted transition-transform group-open:rotate-180" />
          </summary>
          <div class="px-4 pb-4">
            <p class="mb-2 text-xs text-muted">{{ $t('record.placeholders') }}</p>
            <div class="flex flex-wrap gap-1.5">
              <button v-for="name in variableNames" :key="name" type="button" class="rounded bg-elevated px-1.5 py-0.5 font-mono text-xs hover:bg-accented" :title="$t('record.copyVariable')" @click="copyVariable(name)">{{ name }}</button>
            </div>
          </div>
        </details>

        <details v-if="!isNew && record && treeField" class="group rounded-lg border border-default bg-default">
          <summary class="flex cursor-pointer list-none items-center justify-between gap-2 p-4 text-sm font-semibold">
            <span class="flex items-center gap-2"><UIcon name="i-lucide-network" class="size-4 text-muted" />{{ $t('record.children') }} <span v-if="childData?.meta.total_items" class="font-normal text-muted">({{ format.number(childData.meta.total_items) }})</span></span>
            <UIcon name="i-lucide-chevron-down" class="size-4 text-muted transition-transform group-open:rotate-180" />
          </summary>
          <div class="space-y-3 px-4 pb-4">
            <ul v-if="childData?.data.length" class="text-sm divide-y divide-default">
              <li v-for="child in childData.data" :key="child.id" class="flex items-center justify-between gap-3 py-1.5">
                <NuxtLink :to="`/entities/${slug}/${child.id}`" class="text-primary hover:underline truncate">{{ (entity.label_field && child[entity.label_field]) || child.id }}</NuxtLink>
                <UBadge v-if="child._children" :label="String(child._children)" color="neutral" variant="subtle" size="sm" />
              </li>
            </ul>
            <p v-else class="text-sm text-muted">{{ $t('record.noChildren') }}</p>
            <NuxtLink v-if="(childData?.meta.total_items ?? 0) > (childData?.data.length ?? 0)" :to="{ path: `/entities/${slug}`, query: { [`filter[${treeField}]`]: record.id } }" class="block text-sm text-primary hover:underline">{{ $t('record.showAll', { count: format.number(childData!.meta.total_items) }) }}</NuxtLink>
            <UButton v-if="can('create', slug)" block :to="{ path: `/entities/${slug}/new`, query: { parent: record.id } }" icon="i-lucide-plus" size="sm" color="neutral" variant="outline" :label="$t('record.newChild')" />
          </div>
        </details>

        <details v-if="revisions.length" class="group rounded-lg border border-default bg-default">
          <summary class="flex cursor-pointer list-none items-center justify-between gap-2 p-4 text-sm font-semibold">
            <span class="flex items-center gap-2"><UIcon name="i-lucide-history" class="size-4 text-muted" />{{ $t('revisions.title') }} <span class="font-normal text-muted">({{ format.number(revisions.length) }})</span></span>
            <UIcon name="i-lucide-chevron-down" class="size-4 text-muted transition-transform group-open:rotate-180" />
          </summary>
          <ul class="px-4 pb-2 text-sm divide-y divide-default">
            <li v-for="(item, index) in revisions" :key="item.id" class="py-2">
              <div class="flex items-center justify-between gap-2">
                <span class="font-medium">{{ format.relative(item.created_at) }}</span>
                <UButton v-if="index > 0" size="xs" color="neutral" variant="ghost" icon="i-lucide-git-compare" :label="$t('revisions.compare')" @click="openRevision(item)" />
                <UBadge v-else :label="$t('revisions.current')" color="neutral" variant="subtle" size="sm" />
              </div>
              <p class="text-xs text-muted">
                {{ $t(`revisions.actions.${item.action}`) }}<template v-if="item.created_by"> · {{ format.actor(item.created_by) }}</template>
              </p>
              <p v-if="item.changed.length" class="truncate text-xs text-dimmed">{{ item.changed.map(name => name === 'draft' ? $t('record.draft') : entity.fields.find(f => f.name === name)?.label ?? name).join(', ') }}</p>
            </li>
          </ul>
        </details>

        <details v-if="references?.data.length" class="group rounded-lg border border-default bg-default">
          <summary class="flex cursor-pointer list-none items-center justify-between gap-2 p-4 text-sm font-semibold">
            <span class="flex items-center gap-2"><UIcon name="i-lucide-link" class="size-4 text-muted" />{{ $t('library.usedIn') }}</span>
            <UIcon name="i-lucide-chevron-down" class="size-4 text-muted transition-transform group-open:rotate-180" />
          </summary>
          <ul class="space-y-2 px-4 pb-4 text-sm">
            <li v-for="ref in references.data" :key="`${ref.entity}.${ref.field}`" class="flex justify-between gap-3">
              <NuxtLink v-if="ref.count" :to="{ path: `/entities/${ref.entity}`, query: { [`filter[${ref.field}]`]: record!.id } }" class="text-primary hover:underline truncate">
                {{ ref.entity_name }} ({{ ref.field_label }})
              </NuxtLink>
              <span v-else class="text-muted truncate">{{ ref.entity_name }} ({{ ref.field_label }})</span>
              <UBadge :label="String(ref.count)" color="neutral" variant="subtle" size="sm" />
            </li>
          </ul>
        </details>
      </aside>
    </div>

    <UModal v-model:open="leaveOpen" :title="$t('record.leaveTitle')" :description="$t('record.leaveQuestion')">
      <template #footer>
        <div class="flex flex-wrap justify-end gap-3 w-full">
          <UButton color="neutral" variant="ghost" :label="$t('record.keepEditing')" @click="leaveOpen = false" />
          <UButton color="error" variant="outline" icon="i-lucide-trash-2" :label="$t('record.discardChanges')" @click="leave(false)" />
          <UButton :loading="saving" :icon="usesWorkingCopy ? 'i-lucide-file-pen-line' : 'i-lucide-save'" :label="usesWorkingCopy ? $t('record.saveWorkingCopyAndLeave') : $t('record.saveAndLeave')" @click="leave(true)" />
        </div>
      </template>
    </UModal>
    <USlideover
      :open="!!liveBlock"
      side="left"
      :overlay="false"
      :modal="false"
      :dismissible="false"
      :title="liveGroup?.label ?? liveBlock?._type ?? ''"
      :description="livePath && livePath.length > 1 ? $t('preview.liveEditingNested', { list: liveListField?.label ?? '' }) : $t('preview.liveEditing')"
      :ui="{ content: 'max-w-lg' }"
      @update:open="(value: boolean) => { if (!value) liveEdit = null }"
    >
      <template #body>
        <GroupInput v-if="liveGroupField && liveBlock" :model-value="liveBlock" :field="liveGroupField" :entity="slug" :disabled="!editable || liveField?.can_write === false" @update:model-value="updateLiveBlock" />
        <p v-else-if="liveBlock" class="text-sm text-error">{{ $t('blocks.unknownType', { type: liveBlock._type }) }}</p>
      </template>
      <template #footer>
        <div class="flex w-full items-center gap-2">
          <UButton icon="i-lucide-arrow-up" color="neutral" variant="outline" size="sm" :disabled="!editable || liveIndex <= 0" :aria-label="$t('common.moveUp')" @click="moveLiveBlock(-1)" />
          <UButton icon="i-lucide-arrow-down" color="neutral" variant="outline" size="sm" :disabled="!editable || liveIndex >= liveList.length - 1" :aria-label="$t('common.moveDown')" @click="moveLiveBlock(1)" />
          <UButton v-if="editable && liveBlock && liveEdit" icon="i-lucide-copy" color="neutral" variant="ghost" size="sm" :aria-label="$t('blocks.duplicate')" :title="$t('blocks.duplicate')" @click="duplicateBlock({ field: liveEdit.field, key: liveBlock._key })" />
          <UButton v-if="editable && liveBlock && liveEdit" icon="i-lucide-trash-2" color="error" variant="ghost" size="sm" :aria-label="$t('common.delete')" :title="$t('common.delete')" @click="removeBlock({ field: liveEdit.field, key: liveBlock._key })" />
          <span v-if="dirty" class="ms-2 text-xs text-warning">{{ $t('record.unsaved') }}</span>
          <UButton v-if="editable" class="ms-auto" :loading="saving" icon="i-lucide-save" :label="usesWorkingCopy ? $t('record.saveWorkingCopy') : $t('common.save')" @click="saveKeepingState()" />
        </div>
      </template>
    </USlideover>

    <!-- Adding a block from the preview: its type -->
    <USlideover
      :open="!!picker"
      side="left"
      :overlay="false"
      :modal="false"
      :dismissible="false"
      :title="$t('preview.addTitle')"
      :description="picker?.key ? (picker.position === 'before' ? $t('preview.addBefore') : $t('preview.addAfter')) : $t('preview.addEnd')"
      :ui="{ content: 'max-w-md' }"
      @update:open="(value: boolean) => { if (!value) picker = null }"
    >
      <template #body>
        <div class="grid min-w-0 grid-cols-1 gap-2">
          <UInput v-if="pickerTypes.length > 6" v-model="pickerSearch" icon="i-lucide-search" :placeholder="$t('preview.searchBlocks')" class="mb-1 w-full" autofocus />
          <template v-for="section in pickerSections" :key="section.category ?? '-'">
            <p v-if="pickerSections.length > 1 || section.category" class="mt-2 px-1 text-xs font-semibold uppercase tracking-wide text-muted first:mt-0">{{ section.category ?? $t('groups.noCategory') }}</p>
            <button
              v-for="group in section.items"
              :key="group.name"
              type="button"
              class="flex w-full min-w-0 items-start gap-3 rounded-md border border-default p-3 text-start transition-colors hover:border-primary hover:bg-elevated/50"
              @click="insertBlock(group)"
            >
              <UIcon name="i-lucide-square-plus" class="mt-0.5 size-5 shrink-0 text-primary" />
              <span class="min-w-0 flex-1">
                <span class="block font-medium">{{ group.label }}</span>
                <span class="block whitespace-normal break-words text-xs text-muted">{{ group.description || group.fields.map(f => f.label).join(', ') }}</span>
              </span>
            </button>
          </template>
          <p v-if="!pickerTypes.length" class="text-sm text-muted">{{ $t('preview.noTypes') }}</p>
          <p v-else-if="!pickerSections.length" class="text-sm text-muted">{{ $t('preview.noBlocksFound') }}</p>
        </div>
      </template>
    </USlideover>

    <PreviewPanel v-if="hasPreview" ref="previewPanel" v-model:open="previewOpen" :entity="slug" :id="id" :language="languages.length > 1 ? language : undefined" :data="payload()" :version="snapshot" :settings="isAdmin ? `/admin/schema/${entity.id}` : undefined" :editing="!!liveBlock || !!picker" :can-add="editable && blocksFields.length > 0" @select="selectBlock" @insert="openPicker" @remove="removeBlock" @duplicate="duplicateBlock" @move="moveBlock" />

    <UModal v-model:open="compareOpen" :title="$t('record.compareLive')" :ui="{ content: 'sm:max-w-4xl' }">
      <template #body>
        <p v-if="!copyDifferences.length" class="text-sm text-muted">{{ $t('record.noCopyDifferences') }}</p>
        <table v-else-if="workingCopy && record" class="w-full text-sm">
          <thead class="text-left text-muted">
            <tr>
              <th class="pb-2 pe-4">{{ $t('revisions.field') }}</th>
              <th class="pb-2 pe-4">{{ $t('record.live') }}</th>
              <th class="pb-2">{{ $t('record.workingCopy') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-default align-top">
            <tr v-for="field in copyDifferences" :key="field.name">
              <td class="py-2 pe-4 font-medium">{{ field.label }}</td>
              <td class="py-2 pe-4 whitespace-pre-wrap break-words">{{ ['text', 'markdown'].includes(field.type) ? (record[field.name] ?? '–') : format.value(field, record) }}</td>
              <td class="py-2 whitespace-pre-wrap break-words bg-info/5">{{ ['text', 'markdown'].includes(field.type) ? (workingCopy[field.name] ?? '–') : format.value(field, workingCopy) }}</td>
            </tr>
          </tbody>
        </table>
      </template>
    </UModal>
    <UModal v-model:open="revisionOpen" :title="revision ? $t('revisions.of', { date: format.relative(revision.created_at) }) : ''" :ui="{ content: 'sm:max-w-4xl' }">
      <template #body>
        <div v-if="revision && record" class="space-y-4">
          <p v-if="!differences.length" class="text-sm text-muted">{{ $t('revisions.noDifferences') }}</p>
          <table v-else class="w-full text-sm">
            <thead class="text-left text-muted">
              <tr>
                <th v-if="mayUpdate" class="w-8 pb-2" />
                <th class="pb-2 pe-4">{{ $t('revisions.field') }}</th>
                <th class="pb-2 pe-4">{{ $t('revisions.then') }}</th>
                <th class="pb-2">{{ $t('revisions.now') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-default align-top">
              <tr v-for="field in entity.fields.filter(f => differences.includes(f.name))" :key="field.name">
                <td v-if="mayUpdate" class="py-2">
                  <UCheckbox v-if="restorable(field)" :model-value="restoreFields.includes(field.name)" @update:model-value="restoreFields = $event ? [...restoreFields, field.name] : restoreFields.filter(n => n !== field.name)" />
                </td>
                <td class="py-2 pe-4 font-medium">{{ field.label }}</td>
                <td class="py-2 pe-4 whitespace-pre-wrap break-words bg-error/5">{{ ['text', 'markdown'].includes(field.type) ? (revision.record[field.name] ?? '–') : format.value(field, revision.record) }}</td>
                <td class="py-2 whitespace-pre-wrap break-words bg-success/5">{{ ['text', 'markdown'].includes(field.type) ? (record[field.name] ?? '–') : format.value(field, record) }}</td>
              </tr>
            </tbody>
          </table>
          <UAlert v-if="revision.removed.length" color="neutral" variant="subtle" icon="i-lucide-info" :title="$t('revisions.removed')" :description="revision.removed.map(r => `${r.label}: ${typeof r.value === 'string' ? r.value : JSON.stringify(r.value)}`).join(' · ')" />
        </div>
      </template>
      <template v-if="mayUpdate && differences.length" #footer>
        <div class="flex flex-wrap justify-end gap-3 w-full">
          <UButton color="neutral" variant="outline" :loading="saving" :disabled="!restoreFields.length" :label="$t('revisions.restoreSelected', { count: restoreFields.length })" @click="restoreRevision(restoreFields)" />
          <UButton icon="i-lucide-history" :loading="saving" :label="$t('revisions.restoreAll')" @click="restoreRevision(null)" />
        </div>
      </template>
    </UModal>
  </form>
</template>

<style scoped>
/* Locked records: diagonal stripes in the warning color over the faded form */
.locked-stripes {
  background-image: repeating-linear-gradient(135deg, transparent 0 12px, color-mix(in oklab, var(--ui-warning) 9%, transparent) 12px 24px);
}
</style>
