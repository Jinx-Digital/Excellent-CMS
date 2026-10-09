<script setup lang="ts">
import type { Field, MediaFile } from '~/types/api'

// Input of a field type of a plugin: the plugin's web component (an ES module of its assets that
// defines the element). It gets value, config, disabled, field and host - pickMedia(), api(),
// locale - and sends "change" events with the new value. Without its plugin: the value as JSON.
const props = defineProps<{ field: Field, disabled?: boolean }>()
const model = defineModel<unknown>()
const { locale } = useI18n()
const container = ref<HTMLElement>()
const failed = ref<string | null>(null)
let element: (HTMLElement & Record<string, unknown>) | null = null

// Modules load once per page; the custom element is defined by the module
const modules = new Map<string, Promise<unknown>>()
// Scripts come from the API (/api/v1/plugins/…): same host, or the host of an absolute API address
const apiBase = String(useRuntimeConfig().public.apiBaseUrl ?? '')
const base = /^https?:\/\//.test(apiBase) ? new URL(apiBase).origin : ''

// What the component may use of the admin app
const pickerOpen = ref(false)
const pickerAccept = ref<string[]>([])
const pickerMultiple = ref(false)
let pickerResolve: ((files: MediaFile[]) => void) | null = null
const host = {
  locale: () => locale.value,
  pickMedia: (options: { accept?: string[], multiple?: boolean } = {}) => new Promise<MediaFile[]>((resolve) => {
    pickerAccept.value = options.accept ?? []
    pickerMultiple.value = !!options.multiple
    pickerResolve = resolve
    pickerOpen.value = true
  }),
  api: (path: string, options: Record<string, unknown> = {}) => useApi()(path, options),
}
function picked(files: MediaFile[]) {
  pickerResolve?.(files)
  pickerResolve = null
}
watch(pickerOpen, (open) => {
  if (!open && pickerResolve) {
    pickerResolve([])
    pickerResolve = null
  }
})

onMounted(async () => {
  const component = props.field.custom?.component
  if (!component || !container.value) return
  try {
    const url = component.script.startsWith('http') ? component.script : `${base}${component.script}`
    if (!customElements.get(component.tag)) {
      if (!modules.has(url)) modules.set(url, import(/* @vite-ignore */ url))
      await modules.get(url)
    }
    element = document.createElement(component.tag) as HTMLElement & Record<string, unknown>
    Object.assign(element, { config: props.field.custom?.config ?? {}, field: props.field, host, disabled: !!props.disabled, value: model.value ?? null })
    element.addEventListener('change', (event) => {
      if (event instanceof CustomEvent) {
        event.stopPropagation()
        model.value = event.detail ?? null
      }
    })
    container.value.appendChild(element)
  } catch (error) {
    failed.value = error instanceof Error ? error.message : String(error)
  }
})
// Changes from outside (a revision restored, another language): to the element
watch(model, (value) => { if (element && element.value !== value) element.value = value ?? null })
watch(() => props.disabled, (value) => { if (element) element.disabled = !!value })
onBeforeUnmount(() => element?.remove())

// Without the plugin (or its component): the value as JSON, read-only
const fallback = computed(() => model.value === null || model.value === undefined ? '' : typeof model.value === 'string' ? model.value : JSON.stringify(model.value, null, 2))
</script>

<template>
  <div>
    <div v-if="field.custom?.component && !failed" ref="container" class="plugin-field" />
    <div v-else class="space-y-1">
      <UTextarea :model-value="fallback" autoresize :rows="2" disabled class="w-full font-mono text-xs" />
      <p class="text-xs text-warning">{{ failed ? $t('plugins.componentFailed', { error: failed }) : $t('plugins.fieldInactive', { type: field.type }) }}</p>
    </div>
    <MediaPicker v-model:open="pickerOpen" :accept="pickerAccept" :multiple="pickerMultiple" :max="pickerMultiple ? null : 1" @select="picked" />
  </div>
</template>
