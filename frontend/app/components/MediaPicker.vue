<script setup lang="ts">
import type { LibraryFile, MediaFile, Paged } from '~/types/api'

// Choose files from the media library for a media field: only types the field allows, one or
// several (up to `max`). New files can be uploaded into the library right here.
const props = defineProps<{ accept: string[], multiple: boolean, max: number | null }>()
const emit = defineEmits<{ select: [files: MediaFile[]] }>()
const open = defineModel<boolean>('open', { default: false })
const format = useFormat()

const search = ref('')
const keptOnly = ref(false)
const files = ref<LibraryFile[]>([])
const page = ref(1)
const total = ref(0)
const loading = ref(false)
const selected = ref<LibraryFile[]>([])
const uploading = ref(false)
const error = ref<string | null>(null)
const input = ref<HTMLInputElement>()

async function load(reset = true) {
  loading.value = true
  try {
    if (reset) page.value = 1
    const res = await useApi()<Paged<LibraryFile>>('/media', {
      query: { s: search.value || undefined, accept: props.accept.length ? props.accept.join(',') : undefined, kept: keptOnly.value ? 1 : undefined, page: page.value, limit: 36 }
    })
    files.value = reset ? res.data : [...files.value, ...res.data]
    total.value = res.meta.total_items
  } finally {
    loading.value = false
  }
}
function more() {
  page.value++
  load(false)
}

watch(open, (value) => {
  if (!value) return
  selected.value = []
  error.value = null
  load()
})
watchDebounced(search, () => load(), { debounce: 300 })
watch(keptOnly, () => load())

const isSelected = (file: LibraryFile) => selected.value.some(f => f.id === file.id)
function toggle(file: LibraryFile) {
  if (!props.multiple) {
    selected.value = [file]
    return
  }
  if (isSelected(file)) selected.value = selected.value.filter(f => f.id !== file.id)
  else if (props.max === null || selected.value.length < props.max) selected.value = [...selected.value, file]
}

async function upload(event: Event) {
  const chosen = Array.from((event.target as HTMLInputElement).files ?? [])
  uploading.value = true
  error.value = null
  try {
    for (const file of chosen) {
      const body = new FormData()
      body.append('file', file)
      body.append('keep', '1')
      const uploaded = (await useApi()<{ data: MediaFile }>('/media', { method: 'POST', body })).data
      // Uploaded files fit the field? Then they are chosen right away
      await load()
      const listed = files.value.find(f => f.id === uploaded.id)
      if (listed && !isSelected(listed)) toggle(listed)
    }
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    uploading.value = false
    if (input.value) input.value.value = ''
  }
}

function choose() {
  emit('select', selected.value)
  open.value = false
}
</script>

<template>
  <UModal v-model:open="open" :title="$t('media.pickerTitle')" :ui="{ content: 'sm:max-w-4xl' }">
    <template #body>
      <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
          <UInput v-model="search" icon="i-lucide-search" :placeholder="$t('media.searchName')" class="w-full sm:max-w-xs" />
          <USwitch id="picker-kept-only" v-model="keptOnly" :label="$t('media.libraryOnly')" />
          <span v-if="accept.length" class="text-sm text-muted">{{ $t('media.allowedTypes', { types: accept.join(', ') }) }}</span>
          <UButton class="ms-auto" icon="i-lucide-upload" size="sm" color="neutral" variant="outline" :loading="uploading" :label="$t('clients.upload')" @click="input?.click()" />
          <input ref="input" type="file" class="hidden" multiple :accept="accept.length ? accept.join(',') : undefined" @change="upload">
        </div>
        <p v-if="error" class="text-sm text-error">{{ error }}</p>

        <div class="grid gap-3 grid-cols-3 sm:grid-cols-4 lg:grid-cols-6" :class="{ 'opacity-60': loading }">
          <button
            v-for="file in files"
            :key="file.id"
            type="button"
            class="relative text-start rounded-lg border overflow-hidden focus-visible:outline-2 focus-visible:outline-primary"
            :class="isSelected(file) ? 'border-primary ring-2 ring-primary' : 'border-default hover:border-primary'"
            :aria-pressed="isSelected(file)"
            @click="toggle(file)"
          >
            <div class="aspect-square bg-elevated flex items-center justify-center">
              <img v-if="file.is_image" :src="file.url" :alt="file.name" loading="lazy" class="size-full object-cover">
              <UIcon v-else name="i-lucide-file" class="size-8 text-muted" />
            </div>
            <div class="p-1.5 text-xs truncate" :title="file.name">{{ file.name }}</div>
            <UIcon v-if="isSelected(file)" name="i-lucide-circle-check" class="absolute top-1.5 end-1.5 size-5 text-primary bg-default rounded-full" />
          </button>
        </div>
        <EmptyState v-if="!loading && !files.length" :text="$t('media.noMatches')" />
        <div v-if="files.length < total" class="flex justify-center">
          <UButton color="neutral" variant="outline" size="sm" :loading="loading" :label="$t('media.loadMore', { count: format.number(total - files.length) })" @click="more" />
        </div>
      </div>
    </template>
    <template #footer>
      <div class="flex items-center justify-between gap-3 w-full">
        <span class="text-sm text-muted">{{ $t('media.selected', { count: selected.length }) }}<template v-if="multiple && max !== null"> ({{ $t('media.selectedMax', { max }) }})</template></span>
        <div class="flex gap-3">
          <UButton color="neutral" variant="outline" :label="$t('common.cancel')" @click="open = false" />
          <UButton :disabled="!selected.length" :label="$t('media.apply')" @click="choose" />
        </div>
      </div>
    </template>
  </UModal>
</template>
