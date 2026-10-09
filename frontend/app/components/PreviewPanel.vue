<script setup lang="ts">
// Preview of a record on the website, next to the form: the preview address of the entity with a
// short-lived token (the content API then delivers drafts and working copies). Reloads after every
// save (`version`); unsaved changes are posted to the page as they are typed, for websites that
// render them live:
//   window.addEventListener('message', e => { if (e.data?.type === 'excellent:preview') … e.data.record })
const props = defineProps<{ entity: string, id: string, language?: string, data: Record<string, unknown>, version: string, /** Schema page of the entity (admins): where the preview address is set */ settings?: string, /** A block is edited in the panel on the left: fullscreen makes room for it */ editing?: boolean, /** Blocks can be added (button: add block) */ canAdd?: boolean }>()
const open = defineModel<boolean>('open', { default: false })
// Live editing (websites with LiveEdit of the PHP SDK): a click on a block selects it here
const emit = defineEmits<{
  select: [block: { field: string | null, key: string }]
  /** "+" at a block (key) - or "Add block" of the panel (key null: at the end) */
  insert: [place: { field: string | null, key: string | null, position: 'before' | 'after' }]
  remove: [block: { field: string | null, key: string }]
  duplicate: [block: { field: string | null, key: string }]
  /** Dragged in the page: before or after another block (target) */
  move: [move: { field: string | null, key: string, target: string, targetField: string | null, position: 'before' | 'after' }]
}>()
const { t } = useI18n()
const toast = useToast()

const preview = ref<{ token: string, url: string | null, expires_at: string } | null>(null)
const loading = ref(false)
const reloads = ref(0)
const frame = ref<HTMLIFrameElement | null>(null)
const width = ref<'full' | 'tablet' | 'mobile'>('full')
// Fullscreen: the preview over the whole window (live editing opens the block form above it); Esc leaves it
const fullscreen = ref(false)
watch(open, (value) => { if (!value) fullscreen.value = false })
useEventListener(window, 'keydown', (event: KeyboardEvent) => {
  if (event.key === 'Escape' && fullscreen.value && !document.querySelector('[role=dialog]')) fullscreen.value = false
})
const widths = { full: '100%', tablet: '820px', mobile: '390px' }

async function load() {
  loading.value = true
  try {
    preview.value = (await useApi()<{ data: { token: string, url: string | null, expires_at: string } }>(`/entities/${props.entity}/records/${props.id}/preview`, { method: 'POST', body: { lang: props.language } })).data
    reloads.value++
  } catch (error) {
    toast.add({ title: apiErrorMessage(error), color: 'error', icon: 'i-lucide-circle-alert' })
  } finally {
    loading.value = false
  }
}
// A new token when it is about to expire
const expired = () => !preview.value || new Date(preview.value.expires_at).getTime() - Date.now() < 60_000
async function reload() {
  if (expired()) await load()
  else reloads.value++
}
watch(open, (value) => { if (value) load() }, { immediate: true })
watch(() => props.language, () => { if (open.value) load() })
watch(() => props.version, () => { if (open.value) reload() })

// Live: the unsaved values, a moment after typing stops
const origin = computed(() => {
  try {
    return preview.value?.url ? new URL(preview.value.url).origin : null
  } catch {
    return null
  }
})
let timer: ReturnType<typeof setTimeout> | undefined
function post() {
  if (!origin.value || !frame.value?.contentWindow) return
  frame.value.contentWindow.postMessage({ type: 'excellent:preview', entity: props.entity, id: props.id, language: props.language ?? null, record: JSON.parse(JSON.stringify(props.data)) }, origin.value)
}
watch(() => props.data, () => {
  clearTimeout(timer)
  timer = setTimeout(post, 300)
}, { deep: true })
onBeforeUnmount(() => clearTimeout(timer))

// Messages of the page: a block was clicked; the page (again) ready for live values
useEventListener(window, 'message', (event: MessageEvent) => {
  if (!frame.value || event.source !== frame.value.contentWindow || event.origin !== origin.value) return
  const data = event.data as { type?: string, field?: string | null, key?: string } | null
  if (data?.type === 'excellent:select' && typeof data.key === 'string') emit('select', { field: data.field ?? null, key: data.key })
  else if (data?.type === 'excellent:insert' && typeof data.key === 'string') emit('insert', { field: data.field ?? null, key: data.key, position: (data as { position?: string }).position === 'before' ? 'before' : 'after' })
  else if (data?.type === 'excellent:remove' && typeof data.key === 'string') emit('remove', { field: data.field ?? null, key: data.key })
  else if (data?.type === 'excellent:duplicate' && typeof data.key === 'string') emit('duplicate', { field: data.field ?? null, key: data.key })
  else if (data?.type === 'excellent:move' && typeof data.key === 'string' && typeof (data as { target?: unknown }).target === 'string') {
    const move = data as unknown as { field?: string | null, target: string, targetField?: string | null, position?: string }
    emit('move', { field: move.field ?? null, key: data.key, target: move.target, targetField: move.targetField ?? null, position: move.position === 'before' ? 'before' : 'after' })
  }
  else if (data?.type === 'excellent:ready') post()
})
/** Marks a block in the page (and scrolls to it) */
function highlight(key: string | null, scroll = false) {
  if (origin.value && frame.value?.contentWindow) frame.value.contentWindow.postMessage({ type: 'excellent:highlight', key, scroll }, origin.value)
}

async function newTab() {
  await reload()
  if (preview.value?.url) window.open(preview.value.url, '_blank', 'noopener')
}
defineExpose({ newTab, highlight })
</script>

<template>
  <aside v-if="open" class="fixed inset-0 z-40 flex flex-col bg-default shadow-2xl" :class="!fullscreen ? 'border-s border-default xl:inset-y-0 xl:start-auto xl:end-0 xl:w-[min(50vw,60rem)]' : editing && 'sm:ps-[32rem] transition-[padding]'" :aria-label="t('preview.title')">
    <div class="flex items-center gap-2 border-b border-default px-3 py-2">
      <UIcon name="i-lucide-eye" class="size-4 text-muted" />
      <span class="text-sm font-semibold">{{ $t('preview.title') }}</span>
      <UBadge v-if="language" :label="language.toUpperCase()" color="neutral" variant="subtle" size="sm" />
      <div class="ms-auto flex items-center gap-1">
        <UFieldGroup size="xs" class="hidden sm:flex">
          <UButton icon="i-lucide-monitor" :color="width === 'full' ? 'primary' : 'neutral'" variant="outline" :aria-label="$t('preview.desktop')" @click="width = 'full'" />
          <UButton icon="i-lucide-tablet" :color="width === 'tablet' ? 'primary' : 'neutral'" variant="outline" :aria-label="$t('preview.tablet')" @click="width = 'tablet'" />
          <UButton icon="i-lucide-smartphone" :color="width === 'mobile' ? 'primary' : 'neutral'" variant="outline" :aria-label="$t('preview.mobile')" @click="width = 'mobile'" />
        </UFieldGroup>
        <UButton v-if="canAdd" icon="i-lucide-plus" color="neutral" variant="ghost" size="xs" :label="$t('preview.addBlock')" class="hidden sm:inline-flex" @click="emit('insert', { field: null, key: null, position: 'after' })" />
        <UButton icon="i-lucide-refresh-cw" color="neutral" variant="ghost" size="xs" :loading="loading" :aria-label="$t('preview.reload')" @click="reload" />
        <UButton :icon="fullscreen ? 'i-lucide-minimize-2' : 'i-lucide-maximize-2'" color="neutral" variant="ghost" size="xs" :aria-label="fullscreen ? $t('preview.exitFullscreen') : $t('preview.fullscreen')" :title="fullscreen ? $t('preview.exitFullscreen') : $t('preview.fullscreen')" @click="fullscreen = !fullscreen" />
        <UButton icon="i-lucide-external-link" color="neutral" variant="ghost" size="xs" :disabled="!preview?.url" :aria-label="$t('preview.newTab')" @click="newTab" />
        <UButton icon="i-lucide-x" color="neutral" variant="ghost" size="xs" :aria-label="$t('common.close')" @click="open = false" />
      </div>
    </div>
    <div class="flex flex-1 justify-center overflow-auto bg-elevated/50">
      <iframe
        v-if="preview?.url"
        :key="reloads"
        ref="frame"
        :src="preview.url"
        :title="$t('preview.title')"
        class="h-full border-0 bg-white transition-[width]"
        :style="{ width: widths[width] }"
        @load="post"
      />
      <div v-else-if="!loading" class="m-auto max-w-sm space-y-3 p-6 text-center">
        <UIcon name="i-lucide-monitor-off" class="size-8 text-dimmed" />
        <p class="text-sm text-muted">{{ $t('preview.noUrl') }}</p>
        <UButton v-if="settings" :to="settings" icon="i-lucide-settings" color="neutral" variant="outline" size="sm" :label="$t('preview.setUp')" />
      </div>
    </div>
    <p class="border-t border-default px-3 py-1.5 text-xs text-muted">{{ $t('preview.hint') }} {{ $t('preview.liveHint') }}</p>
  </aside>
</template>
