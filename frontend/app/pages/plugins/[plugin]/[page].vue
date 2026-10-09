<script setup lang="ts">
import type { MediaFile } from '~/types/api'

// A page of a plugin (menu "Extensions"): the plugin's web component, like the inputs of its field
// types. It gets config and host - api(), pickMedia(), toast(), locale(), project(), navigate() - and is
// created anew when the project changes.
const route = useRoute()
const { t, locale } = useI18n()
const toast = useToast()
const { project } = useAuth()
const { data } = await usePluginPages()
const page = computed(() => data.value?.find(p => p.plugin === route.params.plugin && p.key === route.params.page) ?? null)
useHead({ title: () => page.value?.label ?? t('nav.pluginPages') })

const container = ref<HTMLElement>()
const failed = ref<string | null>(null)
let element: HTMLElement | null = null

const apiBase = String(useRuntimeConfig().public.apiBaseUrl ?? '')
const base = /^https?:\/\//.test(apiBase) ? new URL(apiBase).origin : ''

const pickerOpen = ref(false)
const pickerAccept = ref<string[]>([])
const pickerMultiple = ref(false)
let pickerResolve: ((files: MediaFile[]) => void) | null = null
const host = {
  locale: () => locale.value,
  project: () => project.value ? { slug: project.value.slug, name: project.value.name } : null,
  api: (path: string, options: Record<string, unknown> = {}) => useApi()(path, options),
  navigate: (path: string) => navigateTo(path),
  toast: (title: string, color: 'success' | 'error' | 'info' | 'warning' = 'success') => toast.add({ title, color, icon: color === 'error' ? 'i-lucide-circle-alert' : 'i-lucide-check' }),
  pickMedia: (options: { accept?: string[], multiple?: boolean } = {}) => new Promise<MediaFile[]>((resolve) => {
    pickerAccept.value = options.accept ?? []
    pickerMultiple.value = !!options.multiple
    pickerResolve = resolve
    pickerOpen.value = true
  }),
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

async function mount() {
  element?.remove()
  element = null
  failed.value = null
  if (!page.value || !container.value) return
  const { tag, script } = page.value.component
  try {
    if (!customElements.get(tag)) await import(/* @vite-ignore */ script.startsWith('http') ? script : `${base}${script}`)
    element = document.createElement(tag)
    Object.assign(element, { config: page.value.config ?? {}, host })
    container.value.appendChild(element)
  } catch (error) {
    failed.value = error instanceof Error ? error.message : String(error)
  }
}
onMounted(mount)
watch([page, () => project.value?.slug], () => nextTick(mount))
onBeforeUnmount(() => element?.remove())
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <AppPageHeader v-if="page" :title="page.label" />
    <UAlert v-if="!page" color="warning" variant="subtle" icon="i-lucide-puzzle" :title="$t('plugins.pageMissing')" />
    <UAlert v-else-if="failed" color="error" variant="subtle" icon="i-lucide-circle-alert" :title="$t('plugins.componentFailed', { error: failed })" />
    <div ref="container" class="plugin-page" />
    <MediaPicker v-model:open="pickerOpen" :accept="pickerAccept" :multiple="pickerMultiple" :max="pickerMultiple ? null : 1" @select="picked" />
  </div>
</template>
