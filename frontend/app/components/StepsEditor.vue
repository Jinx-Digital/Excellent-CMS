<script setup lang="ts">
import type { Entity, PluginStepDef } from '~/types/api'

// Steps of an event: as form (a card per step) or as JSON - both edit the same list of objects,
// which is what the API stores. Keys the form does not know stay as they are.
type Step = Record<string, unknown>
// fields: placeholders of the items of a plugin's source (e.g. the fields of a form); events: those the step "event" can start
const props = defineProps<{ entities: Entity[], source?: string, trigger: string, error?: string, fields?: string[], events?: { value: string, label: string }[] }>()
const steps = defineModel<Step[]>({ required: true })
const { t } = useI18n()
const toast = useToast()

// E-mail recipients: users of the CMS ("user:<id>" - their address when the mail is sent) and
// addresses or placeholders ({{record.email}}), stored together in "to"
const { items: userItems, list: recipientList } = useRecipientUsers()
const recipientUsers = (to: unknown) => recipientList(to).filter(r => r.startsWith('user:'))
const recipientOthers = (to: unknown) => recipientList(to).filter(r => !r.startsWith('user:')).join(', ')
function setRecipients(index: number, users: string[], others: string) {
  set(index, 'to', [...users, ...others.split(/[,;\s]+/).map(s => s.trim()).filter(Boolean)].join(', '))
}

// As binding: a static attribute with "//" confuses the type check of the template
const URL_EXAMPLE = 'https://example.com/hook'
const TYPES = [
  { type: 'webhook', icon: 'i-lucide-webhook' },
  { type: 'email', icon: 'i-lucide-mail' },
  { type: 'create', icon: 'i-lucide-plus' },
  { type: 'update', icon: 'i-lucide-pencil' },
  { type: 'delete', icon: 'i-lucide-trash-2' },
  { type: 'event', icon: 'i-lucide-zap' }
]
// Steps of the active plugins ("<plugin>.<step>"): their form follows the fields they describe
const { data: pluginStepData } = useLazyAsyncData('plugin-steps', () => useApi()<{ data: PluginStepDef[] }>('/admin/plugins/steps').catch(() => ({ data: [] as PluginStepDef[] })))
const pluginSteps = computed(() => pluginStepData.value?.data ?? [])
const pluginStep = (type: unknown) => pluginSteps.value.find(item => item.type === type)
const icon = (type: unknown) => TYPES.find(item => item.type === type)?.icon ?? pluginStep(type)?.icon ?? 'i-lucide-circle'
const stepLabel = (type: unknown) => TYPES.some(item => item.type === type) ? t(`events.steps.${type}`) : pluginStep(type)?.label ?? String(type)

// Form or JSON; switching to the form reads the JSON (and stays if it is invalid)
const mode = ref<'form' | 'json'>('form')
const json = ref('')
const jsonError = ref('')
function toJson() {
  json.value = JSON.stringify(steps.value, null, 2)
  jsonError.value = ''
  mode.value = 'json'
}
function toForm() {
  try {
    const parsed = json.value.trim() === '' ? [] : JSON.parse(json.value)
    if (!Array.isArray(parsed) || parsed.some(step => typeof step !== 'object' || step === null || Array.isArray(step))) throw new Error(t('events.editor.notAList'))
    steps.value = parsed
    jsonError.value = ''
    mode.value = 'form'
  } catch (error) {
    jsonError.value = error instanceof Error ? error.message : String(error)
  }
}
// Typing JSON updates the steps as soon as it is valid
watch(json, (text) => {
  if (mode.value !== 'json') return
  try {
    const parsed = JSON.parse(text)
    if (Array.isArray(parsed)) {
      steps.value = parsed
      jsonError.value = ''
    }
  } catch {
    jsonError.value = t('events.editor.invalidJson')
  }
})

function add(type: string) {
  const defaults: Record<string, Step> = {
    webhook: { type, url: '', secret: '', digest: true },
    email: { type, to: '', subject: '', body: '', digest: false },
    create: { type, entity: '', data: {} },
    update: { type, entity: '', where: { id: '' }, data: {} },
    delete: { type, entity: '', where: { id: '' } },
    event: { type, event: '', data: {} }
  }
  const plugin = pluginStep(type)
  steps.value = [...steps.value, defaults[type] ?? { type, ...Object.fromEntries((plugin?.fields ?? []).map(field => [field.key, field.kind === 'bool' ? !!field.default : (field.default ?? '')])) }]
}
function remove(index: number) {
  steps.value = steps.value.filter((_, i) => i !== index)
}
const drag = useDragSort((from, to) => { steps.value = moved(steps.value, from, to) })
function set(index: number, key: string, value: unknown) {
  steps.value = steps.value.map((step, i) => i === index ? { ...step, [key]: value } : step)
}

// Webhook auth: {type: "bearer", token} or {type: "basic", username, password}; none = no "auth"
type Auth = { type?: 'bearer' | 'basic', token?: string, username?: string, password?: string }
const authOf = (step: Record<string, unknown>): Auth => (step.auth && typeof step.auth === 'object' ? step.auth : {}) as Auth
const authTypes = computed(() => [
  { value: 'none', label: t('events.editor.authNone') },
  { value: 'bearer', label: t('events.editor.authBearer') },
  { value: 'basic', label: t('events.editor.authBasic') }
])
function setAuthType(index: number, type: string) {
  steps.value = steps.value.map((step, i) => {
    if (i !== index) return step
    const { auth: _auth, ...rest } = step
    return type === 'none' ? rest : { ...rest, auth: type === 'bearer' ? { type, token: '' } : { type, username: '', password: '' } }
  })
}
function setAuth(index: number, key: string, value: string) {
  steps.value = steps.value.map((step, i) => i === index ? { ...step, auth: { ...authOf(step), [key]: value } } : step)
}

// "data" and "where" as rows of field and value. A value that is JSON (e.g. {"gte": 10}) is kept as such.
type Row = { field: string, value: string }
const rows = (object: unknown): Row[] => Object.entries((object && typeof object === 'object' ? object : {}) as Record<string, unknown>)
  .map(([field, value]) => ({ field, value: typeof value === 'string' ? value : JSON.stringify(value) }))
function fromRows(list: Row[]): Record<string, unknown> {
  return Object.fromEntries(list.filter(row => row.field).map((row) => {
    const text = row.value.trim()
    if (/^[[{]/.test(text)) {
      try {
        return [row.field, JSON.parse(text)]
      } catch { /* stays text */ }
    }
    return [row.field, row.value]
  }))
}
function setRow(index: number, key: 'data' | 'where', rowIndex: number, part: keyof Row, value: string) {
  const list = rows(steps.value[index]?.[key])
  if (rowIndex === list.length) list.push({ field: '', value: '' })
  list[rowIndex] = { ...list[rowIndex]!, [part]: value }
  set(index, key, fromRows(list))
}
function addRow(index: number, key: 'data' | 'where') {
  // An empty field name would get lost - the new row starts with the first free field
  const used = Object.keys((steps.value[index]?.[key] ?? {}) as Record<string, unknown>)
  const free = fieldsOf(String(steps.value[index]?.entity ?? '')).find(f => !used.includes(f.name))?.name ?? (key === 'where' && !used.includes('id') ? 'id' : '')
  if (free) set(index, key, { ...(steps.value[index]?.[key] as Record<string, unknown> ?? {}), [free]: '' })
}
// The step "event": values of any name - a new row gets a free name to rename
function addValue(index: number) {
  const used = Object.keys((steps.value[index]?.data ?? {}) as Record<string, unknown>)
  let n = used.length + 1
  while (used.includes(`value_${n}`)) n++
  set(index, 'data', { ...(steps.value[index]?.data as Record<string, unknown> ?? {}), [`value_${n}`]: '' })
}
function removeRow(index: number, key: 'data' | 'where', field: string) {
  const object = { ...(steps.value[index]?.[key] as Record<string, unknown> ?? {}) }
  delete object[field]
  set(index, key, object)
}

// Which lists a step has: create sets values, update finds records and sets values, delete finds records
const parts = (type: unknown): ('data' | 'where')[] => type === 'create' ? ['data'] : type === 'update' ? ['where', 'data'] : ['where']

const entityItems = computed(() => props.entities.map(e => ({ value: e.slug, label: e.name })))
const fieldsOf = (slug: string) => props.entities.find(e => e.slug === slug)?.fields ?? []
const fieldItems = (slug: string, withId: boolean) => [
  ...(withId ? [{ value: 'id', label: 'ID' }] : []),
  ...fieldsOf(slug).filter(f => !['autoincrement'].includes(f.type)).map(f => ({ value: f.name, label: f.label })),
  ...(props.entities.find(e => e.slug === slug)?.drafts ? [{ value: 'draft', label: t('record.draft') }] : [])
]

// Placeholders of the record that started the event
const placeholders = computed(() => {
  // Media: the file as the API presents it; variables: the variable with its value
  if (props.fields) return [...props.fields.map(n => `record.${n}`), 'event.name', 'event.action', 'count', 'project.slug']
  const record = props.source === 'media'
    ? ['id', 'name', 'url', 'mime_type', 'size', 'width', 'height', 'is_image', 'kept']
    : props.source === 'variables'
      ? ['name', 'value', 'translations']
      : ['id', ...fieldsOf(props.trigger).map(f => f.name)]
  const old = props.source === 'media' ? ['name'] : props.source === 'variables' ? ['value'] : fieldsOf(props.trigger).slice(0, 3).map(f => f.name)
  return [...record.map(n => `record.${n}`), ...old.map(n => `old.${n}`), 'event.name', 'event.action', 'count', 'project.slug']
})
const shown = (name: string) => `{{${name}}}`
async function copy(text: string) {
  await navigator.clipboard.writeText(`{{${text}}}`)
  toast.add({ title: t('common.copied'), description: `{{${text}}}`, color: 'success', icon: 'i-lucide-clipboard-check' })
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <UFieldGroup size="sm">
        <UButton icon="i-lucide-layout-list" :color="mode === 'form' ? 'primary' : 'neutral'" :variant="mode === 'form' ? 'subtle' : 'outline'" :label="$t('events.editor.form')" @click="mode === 'json' ? toForm() : undefined" />
        <UButton icon="i-lucide-braces" :color="mode === 'json' ? 'primary' : 'neutral'" :variant="mode === 'json' ? 'subtle' : 'outline'" label="JSON" @click="mode === 'form' ? toJson() : undefined" />
      </UFieldGroup>
      <div v-if="mode === 'form'" class="flex flex-wrap gap-1">
        <UButton v-for="item in TYPES" :key="item.type" size="xs" color="neutral" variant="outline" :icon="item.icon" :label="`+ ${$t(`events.steps.${item.type}`)}`" @click="add(item.type)" />
        <UButton v-for="item in pluginSteps" :key="item.type" size="xs" color="neutral" variant="outline" :icon="item.icon" :label="`+ ${item.label}`" :title="item.description" @click="add(item.type)" />
      </div>
    </div>

    <template v-if="mode === 'json'">
      <UTextarea v-model="json" autoresize :rows="10" class="font-mono w-full" :color="jsonError ? 'error' : undefined" :highlight="!!jsonError" />
      <p v-if="jsonError" class="text-sm text-error">{{ jsonError }}</p>
    </template>

    <template v-else>
      <p v-if="!steps.length" class="text-sm text-muted">{{ $t('events.editor.noSteps') }}</p>
      <ol class="space-y-3">
        <li v-for="(step, index) in steps" :key="index" class="rounded-md border border-default p-3 space-y-3" v-bind="drag.row(index, steps.length > 1)" :class="drag.rowClass(index)">
          <div class="flex items-center gap-2">
            <DragHandle v-if="steps.length > 1" class="-ms-1" @move="by => drag.move(index, index + by, steps.length)" />
            <UIcon :name="icon(step.type)" class="size-4 text-primary" />
            <span class="font-medium text-sm">{{ index + 1 }}. {{ stepLabel(step.type) }}</span>
            <UBadge v-if="pluginStep(step.type)" :label="$t('plugins.badge')" color="neutral" variant="subtle" size="sm" />
            <div class="ms-auto flex gap-1">
              <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-x" :aria-label="$t('common.remove')" @click="remove(index)" />
            </div>
          </div>

          <div v-if="step.type === 'webhook'" class="grid gap-3 sm:grid-cols-2">
            <UFormField label="URL" required :help="$t('events.editor.urlHelp')">
              <EnvInput :model-value="String(step.url ?? '')" inline :placeholder="URL_EXAMPLE" @update:model-value="set(index, 'url', $event)" />
            </UFormField>
            <UFormField :label="$t('events.editor.secret')" :help="$t('events.editor.secretHelp')">
              <EnvInput :model-value="String(step.secret ?? '')" secret @update:model-value="set(index, 'secret', $event)" />
            </UFormField>
            <UCheckbox :id="`step-${index}-webhook-digest`" class="sm:col-span-2" :model-value="step.digest !== false" :label="$t('events.editor.webhookDigest')" @update:model-value="set(index, 'digest', !!$event)" />
            <!-- Auth of the receiver: bearer token or basic auth (.htaccess), values or $EVENT_… variables -->
            <details class="group sm:col-span-2 rounded-md border border-default" :open="!!authOf(step).type">
              <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-3 py-2 text-sm font-medium">
                <span class="flex items-center gap-2"><UIcon name="i-lucide-key-round" class="size-4 text-muted" />{{ $t('events.editor.auth') }}
                  <UBadge v-if="authOf(step).type" :label="authOf(step).type === 'bearer' ? $t('events.editor.authBearer') : $t('events.editor.authBasic')" color="neutral" variant="subtle" size="sm" />
                </span>
                <UIcon name="i-lucide-chevron-down" class="size-4 text-muted transition-transform group-open:rotate-180" />
              </summary>
              <div class="grid gap-3 border-t border-default p-3 sm:grid-cols-2">
                <UFormField :label="$t('events.editor.authType')" class="sm:col-span-2">
                  <USelect :model-value="authOf(step).type ?? 'none'" :items="authTypes" class="w-full" @update:model-value="setAuthType(index, $event as string)" />
                </UFormField>
                <UFormField v-if="authOf(step).type === 'bearer'" :label="$t('events.editor.authToken')" class="sm:col-span-2" :help="$t('events.editor.envHelp')">
                  <EnvInput :model-value="String(authOf(step).token ?? '')" secret @update:model-value="setAuth(index, 'token', $event)" />
                </UFormField>
                <template v-if="authOf(step).type === 'basic'">
                  <UFormField :label="$t('events.editor.authUser')">
                    <EnvInput :model-value="String(authOf(step).username ?? '')" @update:model-value="setAuth(index, 'username', $event)" />
                  </UFormField>
                  <UFormField :label="$t('events.editor.authPassword')">
                    <EnvInput :model-value="String(authOf(step).password ?? '')" secret @update:model-value="setAuth(index, 'password', $event)" />
                  </UFormField>
                  <p class="sm:col-span-2 text-xs text-muted">{{ $t('events.editor.envHelp') }}</p>
                </template>
              </div>
            </details>
          </div>

          <div v-if="step.type === 'email'" class="grid gap-3">
            <div class="grid gap-3 sm:grid-cols-2">
              <UFormField :label="$t('events.editor.toUsers')" :help="$t('events.editor.toUsersHelp')">
                <USelectMenu :model-value="recipientUsers(step.to)" :items="userItems" value-key="value" multiple :placeholder="$t('events.editor.chooseUsers')" class="w-full" @update:model-value="setRecipients(index, $event as string[], recipientOthers(step.to))" />
              </UFormField>
              <UFormField :label="$t('events.editor.toAddresses')" :help="$t('events.editor.toAddressesHelp')">
                <UInput :model-value="recipientOthers(step.to)" placeholder="team@example.com, {{record.email}}" class="w-full" @update:model-value="setRecipients(index, recipientUsers(step.to), String($event))" />
              </UFormField>
            </div>
            <UFormField :label="$t('events.editor.subject')" required><UInput :model-value="String(step.subject ?? '')" class="w-full" @update:model-value="set(index, 'subject', $event)" /></UFormField>
            <UFormField :label="$t('events.editor.body')" required><UTextarea :model-value="String(step.body ?? '')" autoresize :rows="3" class="w-full" @update:model-value="set(index, 'body', $event)" /></UFormField>
            <UCheckbox :id="`step-${index}-email-digest`" :model-value="!!step.digest" :label="$t('events.editor.digest')" @update:model-value="set(index, 'digest', !!$event)" />
          </div>

          <div v-if="['create', 'update', 'delete'].includes(String(step.type))" class="grid gap-3">
            <UFormField :label="$t('events.entity')" required>
              <USelect :model-value="String(step.entity ?? '') || undefined" :items="entityItems" :placeholder="$t('fields.chooseEntity')" class="w-full sm:w-72" @update:model-value="set(index, 'entity', $event)" />
            </UFormField>
            <template v-for="key in parts(step.type)" :key="key">
              <UFormField v-if="step.entity" :label="$t(`events.editor.${key}`)" :help="$t(`events.editor.${key}Help`)">
                <div class="space-y-2">
                  <div v-for="(row, rowIndex) in rows(step[key])" :key="row.field" class="flex gap-2">
                    <USelect :model-value="row.field" :items="fieldItems(String(step.entity), key === 'where')" class="w-48 shrink-0" @update:model-value="setRow(index, key, rowIndex, 'field', String($event))" />
                    <UInput :model-value="row.value" :placeholder="shown('record.title')" class="font-mono flex-1" @update:model-value="setRow(index, key, rowIndex, 'value', String($event))" />
                    <UButton color="neutral" variant="ghost" icon="i-lucide-x" :aria-label="$t('common.remove')" @click="removeRow(index, key, row.field)" />
                  </div>
                  <UButton size="xs" color="neutral" variant="outline" icon="i-lucide-plus" :label="$t('events.editor.addField')" @click="addRow(index, key)" />
                </div>
              </UFormField>
            </template>
          </div>

          <div v-if="step.type === 'event'" class="grid gap-3">
            <UFormField :label="$t('events.editor.event')" :help="events?.length ? $t('events.editor.eventHelp') : $t('events.editor.noExecutable')" required>
              <USelect :model-value="String(step.event ?? '') || undefined" :items="events ?? []" :placeholder="$t('events.editor.chooseEvent')" class="w-full sm:w-72" @update:model-value="set(index, 'event', $event)" />
            </UFormField>
            <UFormField :label="$t('events.editor.passData')" :help="$t('events.editor.passDataHelp')">
              <div class="space-y-2">
                <!-- By position: renaming a value keeps the focus -->
                <div v-for="(row, rowIndex) in rows(step.data)" :key="rowIndex" class="flex gap-2">
                  <UInput :model-value="row.field" :placeholder="$t('events.editor.valueName')" class="font-mono w-48 shrink-0" @update:model-value="setRow(index, 'data', rowIndex, 'field', String($event))" />
                  <UInput :model-value="row.value" :placeholder="shown('record.title')" class="font-mono flex-1" @update:model-value="setRow(index, 'data', rowIndex, 'value', String($event))" />
                  <UButton color="neutral" variant="ghost" icon="i-lucide-x" :aria-label="$t('common.remove')" @click="removeRow(index, 'data', row.field)" />
                </div>
                <UButton size="xs" color="neutral" variant="outline" icon="i-lucide-plus" :label="$t('events.editor.addField')" @click="addValue(index)" />
              </div>
            </UFormField>
          </div>

          <div v-if="pluginStep(step.type)" class="grid gap-3">
            <template v-for="field in pluginStep(step.type)!.fields" :key="field.key">
              <UCheckbox v-if="field.kind === 'bool'" :model-value="!!step[field.key]" :label="field.label" :description="field.help" @update:model-value="set(index, field.key, !!$event)" />
              <UFormField v-else :label="field.label" :help="field.help" :required="field.required">
                <USelect v-if="field.kind === 'select'" :model-value="String(step[field.key] ?? '')" :items="field.options ?? []" class="w-full" @update:model-value="set(index, field.key, $event)" />
                <UTextarea v-else-if="field.kind === 'textarea'" :model-value="String(step[field.key] ?? '')" :placeholder="field.placeholder" autoresize :rows="3" class="w-full" @update:model-value="set(index, field.key, $event)" />
                <EnvInput v-else inline :model-value="String(step[field.key] ?? '')" :placeholder="field.placeholder" @update:model-value="set(index, field.key, $event)" />
              </UFormField>
            </template>
          </div>
          <pre v-else-if="!TYPES.some(item => item.type === step.type)" class="text-xs bg-elevated rounded p-2 overflow-x-auto">{{ JSON.stringify(step, null, 2) }}</pre>
          <!-- Otherwise a failing step stops the run -->
          <UCheckbox v-if="index < steps.length - 1" :id="`step-${index}-continue`" :model-value="!!step.continue_on_error" :label="$t('events.editor.continueOnError')" :description="$t('events.editor.continueOnErrorHelp')" @update:model-value="set(index, 'continue_on_error', !!$event)" />
        </li>
      </ol>
    </template>

    <p v-if="error" class="text-sm text-error whitespace-pre-line">{{ error }}</p>

    <div class="flex flex-wrap items-center gap-1 text-xs">
      <span class="text-muted me-1">{{ $t('events.editor.placeholders') }}</span>
      <button v-for="name in placeholders" :key="name" type="button" class="rounded bg-elevated px-1.5 py-0.5 font-mono hover:text-primary" @click="copy(name)">{{ shown(name) }}</button>
    </div>
  </div>
</template>
