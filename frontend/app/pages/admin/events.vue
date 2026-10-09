<script setup lang="ts">
import type { Entity, EventItem, EventRun, PluginEventSource } from '~/types/api'

// Events: react to create/update/delete … of an entity, with a condition (filter of the content API),
// by steps (webhook, e-mail, create/update/delete records). Steps that write records start the
// events of those entities - shown as workflow.
definePageMeta({ admin: true })
const { t } = useI18n()
useHead({ title: () => t('nav.events') })
const format = useFormat()
// Users as recipients of e-mails show as "Name (email)"
const recipients = useRecipientUsers()

const ACTIONS = ['create', 'update', 'delete', 'publish', 'unpublish', 'restore']
// Media and variables have no drafts and no trash
const SOURCES = ['entity', 'media', 'variables'] as const
// "All" in selects (they need a value that is not empty)
const ALL = '-'
// Sources of plugins (e.g. form submissions): their actions, optionally limited to one target
const pluginSources = (await useApi()<{ data: PluginEventSource[] }>('/admin/plugins/event-sources').catch(() => ({ data: [] }))).data
const pluginSource = (source: string) => pluginSources.find(s => s.source === source)
const actionsOf = (source: string) => source === 'entity' ? ACTIONS : pluginSource(source) ? Object.keys(pluginSource(source)!.actions) : ['create', 'update', 'delete']
const actionLabel = (source: string, action: string) => pluginSource(source)?.actions[action] ?? t(`events.actions.${action}`)
const sourceItems = computed(() => [
  ...SOURCES.map(value => ({ value, label: t(`events.sources.${value}`) })),
  ...pluginSources.map(s => ({ value: s.source, label: s.label })),
])
// Placeholders of an item of a plugin source (of its target, or of all)
const pluginFields = computed(() => {
  const source = pluginSource(form.source)
  if (!source) return undefined
  const targets = (source.targets ?? []).filter(target => !form.target || target.value === form.target)
  return [...new Set(targets.flatMap(target => target.fields ?? []))]
})
const { data, refresh } = await useAsyncData('admin-events', () => useApi()<{ data: EventItem[] }>('/admin/events'))
const events = computed(() => data.value?.data ?? [])
const entities = (await useApi()<{ data: Entity[] }>('/admin/entities')).data
const entityName = (slug: string | null) => entities.find(e => e.slug === slug)?.name ?? slug ?? '–'
// What an event listens to, for the workflow
const sourceName = (event: EventItem) => {
  if (event.source === 'entity') return entityName(event.entity)
  const plugin = pluginSource(event.source)
  if (!plugin) return (SOURCES as readonly string[]).includes(event.source) ? t(`events.sources.${event.source}`) : event.source
  const target = event.target ? plugin.targets?.find(item => item.value === event.target)?.label ?? event.target : null
  return target ? `${plugin.label}: ${target}` : plugin.label
}

// Workflow: an event, its steps, and for steps that write records the events of that entity
interface Node { event: EventItem, children: { step: Record<string, unknown>, events: Node[] }[] }
const WRITES: Record<string, string> = { create: 'create', update: 'update', delete: 'delete' }
function node(event: EventItem, chain: string[]): Node {
  return {
    event,
    children: event.steps.map(step => ({
      step,
      events: WRITES[String(step.type)] && chain.length < 3
        ? events.value.filter(e => e.active && e.entity === step.entity && e.actions.includes(WRITES[String(step.type)]!) && !chain.includes(e.id)).map(e => node(e, [...chain, e.id]))
        : []
    }))
  }
}
// Roots: events no other event starts
const started = computed(() => new Set(events.value.flatMap(e => e.steps.flatMap(step => WRITES[String(step.type)] ? events.value.filter(o => o.entity === step.entity && o.actions.includes(WRITES[String(step.type)]!)).map(o => o.id) : []))))
const workflow = computed(() => {
  const roots = events.value.filter(e => !started.value.has(e.id)).map(e => node(e, [e.id]))
  // Events that only start each other (A → B → A) have no start - they become one of their own
  const shown = new Set<string>()
  const walk = (n: Node) => {
    shown.add(n.event.id)
    n.children.forEach(child => child.events.forEach(walk))
  }
  roots.forEach(walk)
  for (const event of events.value) {
    if (!shown.has(event.id)) {
      const root = node(event, [event.id])
      roots.push(root)
      walk(root)
    }
  }
  return roots
})
// What a step does, beside its type (the workflow shows the type as title)
const stepDetail = (step: Record<string, unknown>) => {
  const type = String(step.type)
  if (type === 'webhook') return `${step.url}${step.digest === false ? ` · ${t('events.perRecord')}` : ''}`
  if (type === 'email') return `${recipients.list(step.to).map(recipients.label).join(', ')}${step.digest ? ` · ${t('events.digest')}` : ''}`
  return entityName(String(step.entity ?? ''))
}

// Editor
const open = ref(false)
const editing = ref<EventItem | null>(null)
const form = reactive({ name: '', source: 'entity' as EventItem['source'], entity: '', target: '', actions: ['create'] as string[], mode: 'direct', active: true, condition: '', steps: [] as Record<string, unknown>[] })
// Another source: actions it does not have go away
watch(() => form.source, (source, before) => {
  form.actions = form.actions.filter(a => actionsOf(source).includes(a))
  if (before !== undefined && !form.actions.length && pluginSource(source)) form.actions = actionsOf(source).slice(0, 1)
  if (before !== undefined && source !== editing.value?.source) form.target = ''
})
// Author of the record (events of entities): created or last changed by these users - kept in the condition
// ("created_by": "user:…", several: {"in": [...]}), so the JSON and the picker stay the same
type Condition = Record<string, unknown>
const authorField = ref<'created_by' | 'updated_by'>('created_by')
const parsedCondition = computed<Condition | null | false>(() => {
  const text = form.condition.trim()
  if (!text) return null
  try {
    const value = JSON.parse(text)
    return value && typeof value === 'object' && !Array.isArray(value) ? value as Condition : false
  } catch {
    return false
  }
})
const usersOf = (value: unknown): string[] => {
  if (typeof value === 'string') return value.split(',')
  if (!value || typeof value !== 'object') return []
  const operators = value as Record<string, unknown>
  if (Array.isArray(operators.in)) return operators.in.map(String)
  if (typeof operators.in === 'string') return operators.in.split(',')
  return typeof operators.eq === 'string' ? [operators.eq] : []
}
const writeAuthors = (field: 'created_by' | 'updated_by', list: string[], without?: string) => {
  const condition = parsedCondition.value
  if (condition === false) return
  const next: Condition = { ...(condition ?? {}) }
  if (without) delete next[without]
  if (list.length) next[field] = list.length === 1 ? list[0] : { in: list }
  else delete next[field]
  form.condition = Object.keys(next).length ? JSON.stringify(next, null, 2) : ''
}
const authors = computed<string[]>({
  get: () => parsedCondition.value ? usersOf(parsedCondition.value[authorField.value]).map(s => s.trim()).filter(s => s.startsWith('user:')) : [],
  set: list => writeAuthors(authorField.value, list),
})
// Created by ↔ last changed by: the chosen users go along
watch(authorField, (field, before) => {
  const condition = parsedCondition.value
  if (condition && before && field !== before && condition[before] !== undefined) writeAuthors(field, usersOf(condition[before]), before)
})

const { submit, saving, errors } = useSubmit()
function edit(event: EventItem | null) {
  editing.value = event
  Object.assign(form, event
    ? { name: event.name, source: event.source ?? 'entity', entity: event.entity ?? '', target: event.target ?? '', actions: [...event.actions], mode: event.mode, active: event.active, condition: event.condition ? JSON.stringify(event.condition, null, 2) : '', steps: JSON.parse(JSON.stringify(event.steps)) }
    : { name: '', source: 'entity', entity: entities[0]?.slug ?? '', target: '', actions: ['create'], mode: 'direct', active: true, condition: '', steps: [] })
  errors.value = {}
  testResult.value = null
  // A condition on the last change: the picker shows that
  authorField.value = event?.condition && 'updated_by' in event.condition && !('created_by' in event.condition) ? 'updated_by' : 'created_by'
  open.value = true
}
async function save() {
  const res = await submit(() => editing.value
    ? useApi()<{ data: EventItem }>(`/admin/events/${editing.value.id}`, { method: 'PUT', body: form })
    : useApi()<{ data: EventItem }>('/admin/events', { method: 'POST', body: form }), t('events.saved'))
  if (res) {
    open.value = false
    await refresh()
  }
}
async function remove(event: EventItem) {
  if (await submit(() => useApi()(`/admin/events/${event.id}`, { method: 'DELETE' }), t('events.deleted')) !== null) await refresh()
}

// Test run with one record
const testRecord = ref('')
const testResult = ref<{ matches: boolean, steps: { type: string, preview: unknown }[] } | null>(null)
async function test() {
  const res = await submit(() => useApi()<{ data: { matches: boolean, steps: { type: string, preview: unknown }[] } }>(`/admin/events/${editing.value!.id}/test`, { method: 'POST', body: { record: testRecord.value } }), '')
  if (res) testResult.value = res.data
}

// Runs with their progress - refreshed while one is waiting or running
const runsOf = ref<EventItem | null>(null)
const runs = ref<EventRun[]>([])
const runsOpen = ref(false)
async function loadRuns() {
  if (runsOf.value && runsOpen.value) runs.value = (await useApi()<{ data: EventRun[] }>(`/admin/events/${runsOf.value.id}/runs`)).data
}
async function showRuns(event: EventItem) {
  runsOf.value = event
  runs.value = []
  runsOpen.value = true
  await loadRuns()
}
const busy = computed(() => runs.value.some(r => r.status === 'queued' || r.status === 'running') || events.value.some(e => e.last_run && ['queued', 'running'].includes(e.last_run.status)))
const timer = useIntervalFn(async () => {
  await Promise.all([refresh(), loadRuns()])
}, 3000, { immediate: false })
watch(busy, value => value ? timer.resume() : timer.pause(), { immediate: true })
async function retry(run: EventRun) {
  if (await submit(() => useApi()(`/admin/event-runs/${run.id}/retry`, { method: 'POST' }), t('events.retried')) !== null) await Promise.all([loadRuns(), refresh()])
}
const statusColor = (status: string) => ({ done: 'success', failed: 'error', running: 'info', queued: 'warning', waiting: 'neutral' } as const)[status as 'done'] ?? 'neutral'
const percent = (run: EventRun) => {
  const total = run.steps.length || 1
  return Math.round(run.steps.reduce((sum, s) => sum + (s.status === 'done' ? 1 : s.total ? s.done / s.total : 0), 0) / total * 100)
}
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <AppPageHeader :title="$t('nav.events')" :subtitle="$t('events.subtitle')">
      <template #actions>
        <UButton icon="i-lucide-plus" :label="$t('events.new')" @click="edit(null)" />
      </template>
    </AppPageHeader>

    <!-- Workflow: event → steps → events those steps start -->
    <div class="space-y-6">
      <UCard v-if="!events.length" :ui="{ body: 'p-0 sm:p-0' }"><EmptyState icon="i-lucide-workflow" :text="$t('events.empty')" /></UCard>
      <UCard v-for="root in workflow" :key="root.event.id" :ui="{ body: 'p-5 sm:p-6' }">
        <EventWorkflowNode :node="root" :source-name="sourceName" :step-detail="stepDetail" :status-color="statusColor" :percent="percent" @edit="edit" @runs="showRuns" @remove="remove" />
      </UCard>
    </div>

    <!-- Runs and their progress -->
    <UModal v-model:open="runsOpen" :title="runsOf ? $t('events.runsOf', { event: runsOf.name }) : ''" :ui="{ content: 'sm:max-w-3xl' }">
      <template #body>
        <p v-if="!runs.length" class="text-sm text-muted">{{ $t('events.noRuns') }}</p>
        <ul v-else class="divide-y divide-default -my-2">
          <li v-for="run in runs" :key="run.id" class="py-3 space-y-2">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
              <UBadge :label="$t(`events.status.${run.status}`)" :color="statusColor(run.status)" variant="subtle" />
              <span>{{ format.relative(run.created_at) }}</span>
              <span class="text-muted">{{ $t('events.records', run.count) }}</span>
              <span v-if="run.depth" class="text-muted">· {{ $t('events.chained', { depth: run.depth }) }}</span>
              <UButton v-if="run.status === 'failed'" class="ms-auto" size="xs" icon="i-lucide-rotate-ccw" color="neutral" variant="outline" :loading="saving" :label="$t('events.retry')" @click="retry(run)" />
            </div>
            <ol class="grid gap-2 sm:grid-cols-2">
              <li v-for="(step, index) in run.steps" :key="index" class="rounded border border-default p-2 text-xs">
                <div class="flex items-center justify-between gap-2">
                  <span class="font-medium">{{ index + 1 }}. {{ $t(`events.steps.${step.type}`) }}</span>
                  <UBadge :label="$t(`events.status.${step.status}`)" :color="statusColor(step.status)" variant="subtle" size="sm" />
                </div>
                <UProgress v-if="step.total" class="mt-2" :model-value="step.done" :max="step.total" size="xs" :color="statusColor(step.status)" />
                <div v-if="step.total" class="mt-1 text-muted">{{ step.done }} / {{ step.total }}</div>
                <div v-if="step.error" class="mt-1 text-error break-words">{{ step.error }}</div>
              </li>
            </ol>
          </li>
        </ul>
      </template>
    </UModal>

    <UModal v-model:open="open" :title="editing ? $t('events.edit') : $t('events.new')" :ui="{ content: 'sm:max-w-3xl' }">
      <template #body>
        <div class="space-y-4">
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField :label="$t('common.name')" :error="errors.name" required><UInput v-model="form.name" class="w-full" /></UFormField>
            <UFormField :label="$t('events.source')" :error="errors.source" required>
              <USelect v-model="form.source" :items="sourceItems" class="w-full" />
            </UFormField>
          </div>
          <UFormField v-if="form.source === 'entity'" :label="$t('events.entity')" :error="errors.entity" required>
            <USelect v-model="form.entity" :items="entities.map(e => ({ value: e.slug, label: e.name }))" class="w-full sm:w-1/2" />
          </UFormField>
          <UFormField v-else-if="pluginSource(form.source)?.targets" :label="pluginSource(form.source)!.target_label || $t('events.target')" :error="errors.target">
            <USelect :model-value="form.target || ALL" :items="[{ value: ALL, label: $t('events.allTargets') }, ...pluginSource(form.source)!.targets!.map(item => ({ value: item.value, label: item.label }))]" class="w-full sm:w-1/2" @update:model-value="form.target = $event === ALL ? '' : String($event)" />
          </UFormField>
          <UFormField :label="$t('events.on')" :error="errors.actions" required>
            <div class="flex flex-wrap gap-4">
              <UCheckbox v-for="action in actionsOf(form.source)" :id="`event-action-${action}`" :key="action" :model-value="form.actions.includes(action)" :label="actionLabel(form.source, action)" @update:model-value="form.actions = $event ? [...form.actions, action] : form.actions.filter(a => a !== action)" />
            </div>
          </UFormField>
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField :label="$t('events.mode')" :help="$t(`events.modeHelp.${form.mode}`)" :error="errors.mode">
              <USelect v-model="form.mode" :items="[{ value: 'direct', label: $t('events.modes.direct') }, { value: 'queue', label: $t('events.modes.queue') }]" class="w-full" />
            </UFormField>
            <UFormField :label="$t('events.state')"><USwitch id="event-active" v-model="form.active" :label="$t('events.active')" class="mt-2" /></UFormField>
          </div>
          <UFormField v-if="form.source === 'entity'" :label="$t('events.author')" :help="parsedCondition === false ? $t('events.authorInvalid') : $t('events.authorHelp')">
            <div class="flex flex-wrap gap-2">
              <USelect v-model="authorField" :items="[{ value: 'created_by', label: $t('events.authorCreated') }, { value: 'updated_by', label: $t('events.authorUpdated') }]" :disabled="parsedCondition === false" class="w-48" />
              <USelectMenu v-model="authors" :items="recipients.items.value" value-key="value" multiple :placeholder="$t('events.authorAll')" :disabled="parsedCondition === false" class="min-w-64 flex-1" />
            </div>
          </UFormField>
          <UFormField :label="$t('events.condition')" :help="$t('events.conditionHelp')" :error="errors.condition">
            <UTextarea v-model="form.condition" autoresize :rows="3" class="font-mono w-full" placeholder='{ "draft": false, "price": { "gte": 10 }, "_changed": ["price"] }' />
          </UFormField>
          <UFormField :label="$t('events.stepsLabel')" :help="$t('events.stepsHelp')" required>
            <StepsEditor v-model="form.steps" :entities="entities" :source="form.source" :trigger="form.entity" :fields="pluginFields" :error="Array.isArray(errors.steps) ? errors.steps.join('\n') : errors.steps" />
          </UFormField>
          <div v-if="editing" class="rounded-md border border-default p-3 space-y-2">
            <div class="flex flex-wrap items-end gap-2">
              <UFormField :label="$t('events.testRecord')" :help="pluginSource(form.source) ? $t('events.testWhichPlugin') : $t(`events.testWhich.${form.source}`)" class="flex-1"><UInput v-model="testRecord" :placeholder="form.source === 'variables' ? 'url' : 'ID'" class="font-mono w-full" /></UFormField>
              <UButton icon="i-lucide-flask-conical" color="neutral" variant="outline" :loading="saving" :label="$t('events.test')" @click="test" />
            </div>
            <template v-if="testResult">
              <UBadge :label="testResult.matches ? $t('events.matches') : $t('events.noMatch')" :color="testResult.matches ? 'success' : 'warning'" variant="subtle" />
              <pre class="text-xs bg-elevated rounded p-2 overflow-x-auto max-h-72">{{ JSON.stringify(testResult.steps, null, 2) }}</pre>
            </template>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-3 w-full">
          <UButton color="neutral" variant="outline" :label="$t('common.cancel')" @click="open = false" />
          <UButton :loading="saving" icon="i-lucide-save" :label="$t('common.save')" @click="save" />
        </div>
      </template>
    </UModal>
  </div>
</template>
