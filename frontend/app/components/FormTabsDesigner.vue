<script setup lang="ts">
import type { Entity, EntityTab } from '~/types/api'

// Form designer of an entity (schema, admins): the fields of the record form in tabs. There is always
// one tab, holding every field no other tab has; fields are dragged between tabs (or moved with the
// arrow keys of their handle), tabs are added, named, sorted and removed (their fields go back to
// the first tab). Only the arrangement - the fields stay columns of the entity.
const props = defineProps<{ entity: Entity }>()
const emit = defineEmits<{ saved: [] }>()
const { t } = useI18n()
const { submit, saving, errors } = useSubmit()

const tabs = ref<EntityTab[]>([])
function reset() {
  tabs.value = JSON.parse(JSON.stringify(props.entity.tabs?.length ? props.entity.tabs : [{ key: 'main', label: '', fields: props.entity.fields.map(f => f.name) }]))
}
watch(() => props.entity, reset, { immediate: true })
const dirty = computed(() => JSON.stringify(tabs.value) !== JSON.stringify(props.entity.tabs?.length ? props.entity.tabs : [{ key: 'main', label: '', fields: props.entity.fields.map(f => f.name) }]))
const fieldOf = (name: string) => props.entity.fields.find(f => f.name === name)

function addTab() {
  let n = tabs.value.length + 1
  while (tabs.value.some(tab => tab.key === `tab${n}`)) n++
  // The first tab needs a name once there are two
  if (tabs.value.length === 1 && !tabs.value[0]!.label) tabs.value[0]!.label = t('formTabs.defaultName')
  tabs.value.push({ key: `tab${n}`, label: t('formTabs.newName', { n: tabs.value.length + 1 }), fields: [] })
}
function removeTab(index: number) {
  const [tab] = tabs.value.splice(index, 1)
  tabs.value[0]!.fields.push(...tab!.fields)
  if (tabs.value.length === 1) tabs.value[0]!.label = ''
}
function moveTab(from: number, to: number) {
  if (to < 0 || to > tabs.value.length || from === to) return
  const [tab] = tabs.value.splice(from, 1)
  tabs.value.splice(from < to ? to - 1 : to, 0, tab!)
}

// Dragging tabs by the handle of their header: before or after another tab
const draggingTab = ref<number | null>(null)
const overTab = ref<number | null>(null)
function onTabDragStart(event: DragEvent, index: number) {
  draggingTab.value = index
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move'
    event.dataTransfer.setData('text/plain', tabs.value[index]!.key)
  }
}
function onTabDragOver(event: DragEvent, index: number) {
  if (draggingTab.value === null) return
  event.preventDefault()
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  // Side by side (or below each other on small screens): the half decides
  const after = rect.width > rect.height * 1.5 && window.innerWidth >= 768 ? event.clientX > rect.left + rect.width / 2 : event.clientY > rect.top + rect.height / 2
  overTab.value = index + (after ? 1 : 0)
}
function onTabDrop(event: DragEvent) {
  if (draggingTab.value === null) return
  event.preventDefault()
  if (overTab.value !== null) moveTab(draggingTab.value, overTab.value)
  onTabDragEnd()
}
function onTabDragEnd() {
  draggingTab.value = null
  overTab.value = null
  document.querySelectorAll('[data-tab-box][draggable]').forEach(el => el.removeAttribute('draggable'))
}

// Dragging fields: onto a field (before/after it) or into the empty end of a tab
const dragging = ref<{ tab: number, index: number } | null>(null)
const over = ref<{ tab: number, index: number } | null>(null)
function moveField(from: { tab: number, index: number }, to: { tab: number, index: number }) {
  const [name] = tabs.value[from.tab]!.fields.splice(from.index, 1)
  const index = from.tab === to.tab && from.index < to.index ? to.index - 1 : to.index
  tabs.value[to.tab]!.fields.splice(Math.max(0, Math.min(index, tabs.value[to.tab]!.fields.length)), 0, name!)
}
function onDragStart(event: DragEvent, tab: number, index: number) {
  event.stopPropagation()
  dragging.value = { tab, index }
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move'
    event.dataTransfer.setData('text/plain', tabs.value[tab]!.fields[index]!)
  }
}
function onDragOver(event: DragEvent, tab: number, index: number) {
  if (!dragging.value) return
  event.preventDefault()
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  over.value = { tab, index: index + (event.clientY > rect.top + rect.height / 2 ? 1 : 0) }
}
function onDragOverEnd(event: DragEvent, tab: number) {
  if (!dragging.value || event.target !== event.currentTarget) return
  event.preventDefault()
  over.value = { tab, index: tabs.value[tab]!.fields.length }
}
function onDrop(event: DragEvent) {
  event.preventDefault()
  if (dragging.value && over.value) moveField(dragging.value, over.value)
  onDragEnd()
}
function onDragEnd() {
  dragging.value = null
  over.value = null
  document.querySelectorAll('[data-tab-field][draggable]').forEach(el => el.removeAttribute('draggable'))
}
// Keyboard: up/down within the tab, left/right into the neighbouring tab
function onKey(event: KeyboardEvent, tab: number, index: number) {
  const steps: Record<string, [number, number]> = { ArrowUp: [0, -1], ArrowDown: [0, 1], ArrowLeft: [-1, 0], ArrowRight: [1, 0] }
  const step = steps[event.key]
  if (!step) return
  event.preventDefault()
  const toTab = tab + step[0]
  if (toTab < 0 || toTab >= tabs.value.length) return
  const toIndex = step[0] ? tabs.value[toTab]!.fields.length : index + step[1] + (step[1] > 0 ? 1 : 0)
  if (!step[0] && (index + step[1] < 0 || index + step[1] >= tabs.value[tab]!.fields.length)) return
  moveField({ tab, index }, { tab: toTab, index: toIndex })
  const name = step[0] ? tabs.value[toTab]!.fields[tabs.value[toTab]!.fields.length - 1] : tabs.value[tab]!.fields[index + step[1]]
  nextTick(() => document.querySelector<HTMLElement>(`[data-tab-field="${name}"] button`)?.focus())
}
const marker = (tab: number, index: number) => over.value?.tab === tab && over.value.index === index && dragging.value

async function save() {
  const body = { tabs: tabs.value.length === 1 ? [] : tabs.value }
  if (await submit(() => useApi()(`/admin/entities/${props.entity.id}`, { method: 'PUT', body }), t('formTabs.saved')) !== null) emit('saved')
}
const tabError = (index: number) => {
  const error = errors.value[`tabs.${index}`]
  return Array.isArray(error) ? error.join(' ') : error
}
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 class="font-semibold">{{ $t('formTabs.title') }}</h2>
          <p class="text-sm text-muted">{{ $t('formTabs.help') }}</p>
        </div>
        <div class="flex gap-2">
          <UButton icon="i-lucide-plus" size="sm" color="neutral" variant="outline" :label="$t('formTabs.add')" :disabled="tabs.length >= 20" @click="addTab" />
          <UButton icon="i-lucide-save" size="sm" :loading="saving" :disabled="!dirty" :label="$t('common.save')" @click="save" />
        </div>
      </div>
    </template>
    <div class="grid gap-3" :class="tabs.length > 1 ? 'md:grid-cols-2 xl:grid-cols-3' : ''">
      <section
        v-for="(tab, ti) in tabs"
        :key="tab.key"
        data-tab-box
        class="flex min-w-0 flex-col rounded-lg border border-default bg-elevated/30 transition-shadow"
        :class="[draggingTab === ti ? 'opacity-40' : '', draggingTab !== null && overTab === ti && draggingTab !== ti ? 'shadow-[-3px_0_0_var(--ui-primary)]' : '', draggingTab !== null && overTab === ti + 1 && draggingTab !== ti ? 'shadow-[3px_0_0_var(--ui-primary)]' : '']"
        @dragstart="onTabDragStart($event, ti)"
        @dragover="onTabDragOver($event, ti)"
        @drop="onTabDrop"
        @dragend="onTabDragEnd"
      >
        <header class="flex items-center gap-1 border-b border-default p-2">
          <UButton
            v-if="tabs.length > 1"
            icon="i-lucide-grip-vertical"
            color="neutral"
            variant="ghost"
            size="xs"
            class="cursor-grab text-dimmed active:cursor-grabbing"
            :aria-label="$t('formTabs.dragTab')"
            :title="$t('formTabs.dragTab')"
            @pointerdown="($event.currentTarget as HTMLElement).closest('section')?.setAttribute('draggable', 'true')"
            @pointerup="($event.currentTarget as HTMLElement).closest('section')?.removeAttribute('draggable')"
            @keydown.left.prevent="moveTab(ti, ti - 1)"
            @keydown.right.prevent="moveTab(ti, ti + 2)"
          />
          <UIcon v-else name="i-lucide-panel-top" class="ms-1 size-4 shrink-0 text-muted" />
          <UInput
            v-if="tabs.length > 1"
            v-model="tab.label"
            size="sm"
            variant="ghost"
            class="min-w-0 flex-1 font-medium"
            :placeholder="$t('formTabs.name')"
            :aria-label="$t('formTabs.name')"
            maxlength="60"
          />
          <span v-else class="flex-1 px-2 text-sm font-medium">{{ $t('formTabs.single') }}</span>
          <template v-if="tabs.length > 1">
            <UButton icon="i-lucide-trash-2" size="xs" color="error" variant="ghost" :aria-label="$t('formTabs.remove')" :title="$t('formTabs.removeHelp')" @click="removeTab(ti)" />
          </template>
        </header>
        <p v-if="tabError(ti)" class="px-3 pt-2 text-xs text-error">{{ tabError(ti) }}</p>
        <ul class="flex min-h-24 flex-1 flex-col gap-1.5 p-2" @dragover="onDragOverEnd($event, ti)" @drop="onDrop">
          <li
            v-for="(name, fi) in tab.fields"
            :key="name"
            :data-tab-field="name"
            class="flex items-center gap-2 rounded-md border border-default bg-default px-2 py-1.5 text-sm"
            :class="[dragging?.tab === ti && dragging.index === fi ? 'opacity-40' : '', marker(ti, fi) ? 'shadow-[inset_0_2px_0_var(--ui-primary)]' : '', marker(ti, fi + 1) && fi === tab.fields.length - 1 ? 'shadow-[inset_0_-2px_0_var(--ui-primary)]' : '']"
            @dragstart="onDragStart($event, ti, fi)"
            @dragover.stop="onDragOver($event, ti, fi)"
            @drop.stop="onDrop"
            @dragend="onDragEnd"
          >
            <UButton
              icon="i-lucide-grip-vertical"
              color="neutral"
              variant="ghost"
              size="xs"
              class="cursor-grab text-dimmed active:cursor-grabbing"
              :aria-label="$t('formTabs.dragField')"
              :title="$t('formTabs.dragField')"
              @pointerdown="($event.currentTarget as HTMLElement).closest('li')?.setAttribute('draggable', 'true')"
              @pointerup="($event.currentTarget as HTMLElement).closest('li')?.removeAttribute('draggable')"
              @keydown="onKey($event, ti, fi)"
            />
            <UIcon v-if="fieldOf(name)" :name="typeIcon(fieldOf(name)!)" class="size-4 shrink-0 text-muted" />
            <span class="min-w-0 flex-1 truncate">{{ fieldOf(name)?.label ?? name }}</span>
            <span class="hidden font-mono text-xs text-dimmed sm:inline">{{ name }}</span>
          </li>
          <li v-if="!tab.fields.length" class="pointer-events-none flex flex-1 items-center justify-center rounded-md border border-dashed px-3 py-4 text-center text-xs" :class="over?.tab === ti ? 'border-primary text-primary bg-primary/5' : 'border-default text-muted'">
            {{ $t('formTabs.dropHere') }}
          </li>
        </ul>
      </section>
    </div>
    <p v-if="tabs.length > 1" class="mt-3 text-xs text-muted">{{ $t('formTabs.newFields') }}</p>
  </UCard>
</template>
