<script setup lang="ts">
import type { LibraryFile, Paged } from '~/types/api'

// Media library: every uploaded file and where it is used. Files uploaded here are kept even while
// nothing uses them and can be chosen in any media field; files uploaded in a field are removed by
// `./yii cleanup` 24 hours after nothing uses them any more (unless they are kept).
const { t } = useI18n()
useHead({ title: () => t('nav.media') })
const route = useRoute()
const router = useRouter()
const format = useFormat()
const toast = useToast()

const search = ref(String(route.query.s ?? ''))
const kind = ref(String(route.query.kind ?? 'all'))
const usage = ref(String(route.query.usage ?? 'all'))
const keptOnly = ref(route.query.kept === '1')
const page = ref(Number(route.query.page ?? 1))
const limit = 48

const query = computed(() => ({
  s: search.value || undefined,
  kind: kind.value === 'all' ? undefined : kind.value,
  usage: usage.value === 'all' ? undefined : usage.value,
  kept: keptOnly.value ? '1' : undefined,
  page: page.value,
  limit
}))
const { data, pending, refresh } = await useAsyncData('media-library', () => useApi()<Paged<LibraryFile>>('/media', { query: query.value }), { watch: [query] })
watchDebounced(search, () => { page.value = 1 }, { debounce: 300 })
watch([kind, usage, keptOnly], () => { page.value = 1 })

// Upload into the library: button or drag & drop onto the page
const input = ref<HTMLInputElement>()
const uploading = ref(false)
const dragging = ref(false)
async function upload(list: FileList | File[] | null | undefined) {
  const chosen = Array.from(list ?? [])
  if (!chosen.length) return
  uploading.value = true
  let done = 0
  try {
    for (const file of chosen) {
      const body = new FormData()
      body.append('file', file)
      body.append('keep', '1')
      await useApi()('/media', { method: 'POST', body })
      done++
    }
    toast.add({ title: t('library.uploaded', done), color: 'success', icon: 'i-lucide-upload' })
  } catch (error) {
    toast.add({ title: apiErrorMessage(error), description: done ? t('library.uploadedBefore', done) : undefined, color: 'error', icon: 'i-lucide-circle-alert' })
  } finally {
    uploading.value = false
    if (input.value) input.value.value = ''
    await refresh()
  }
}

const { isAdmin } = useAuth()
// Admins: which file types may be uploaded
const typesOpen = ref(false)
// The file whose details are open (MediaDetail)
const selectedId = ref<string | null>(null)
watch(query, (value) => {
  router.replace({ query: Object.fromEntries(Object.entries(value).filter(([key, v]) => v !== undefined && key !== 'limit' && !(key === 'page' && v === 1))) })
})


const size = (bytes: number) => bytes >= 1024 * 1024 ? `${format.number(bytes / 1024 / 1024, 1)} MB` : `${format.number(Math.max(1, Math.round(bytes / 1024)))} KB`
const extension = (file: LibraryFile) => file.name.includes('.') ? file.name.split('.').pop()!.toUpperCase() : file.mime_type.split('/')[1]?.toUpperCase()

</script>

<template>
  <div>
    <AppPageHeader :title="$t('nav.media')" :subtitle="$t('library.subtitle')">
      <template #actions>
        <UButton v-if="isAdmin" icon="i-lucide-file-cog" color="neutral" variant="outline" :label="$t('mediaTypes.title')" @click="typesOpen = true" />
        <UButton icon="i-lucide-upload" :loading="uploading" :label="$t('clients.upload')" @click="input?.click()" />
        <input ref="input" type="file" class="hidden" multiple @change="upload(($event.target as HTMLInputElement).files)">
      </template>
    </AppPageHeader>

    <div
      class="mb-4 rounded-lg border-2 border-dashed px-4 py-6 text-center text-sm transition-colors"
      :class="dragging ? 'border-primary bg-primary/5 text-primary' : 'border-default text-muted'"
      @dragover.prevent="dragging = true"
      @dragleave="dragging = false"
      @drop.prevent="dragging = false; upload($event.dataTransfer?.files)"
    >
      <UIcon name="i-lucide-cloud-upload" class="size-6 mb-1" />
      <div>{{ $t('library.dropHere') }}</div>
    </div>

    <div class="flex flex-wrap items-center gap-3 mb-4">
      <UInput v-model="search" icon="i-lucide-search" :placeholder="$t('media.searchName')" class="w-full sm:max-w-xs" />
      <USelect v-model="kind" :items="[{ value: 'all', label: $t('library.allTypes') }, { value: 'image', label: $t('library.images') }, { value: 'file', label: $t('library.otherFiles') }]" class="w-44" />
      <USelect v-model="usage" :items="[{ value: 'all', label: $t('library.usedAndFree') }, { value: 'used', label: $t('library.used') }, { value: 'unused', label: $t('library.unused') }]" class="w-48" />
      <USwitch id="library-kept-only" v-model="keptOnly" :label="$t('media.libraryOnly')" />
      <span class="ms-auto text-sm text-muted">{{ $t('library.count', { count: format.number(data?.meta.total_items ?? 0) }, data?.meta.total_items ?? 0) }}</span>
    </div>

    <div class="grid gap-4 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6" :class="{ 'opacity-60': pending }">
      <button
        v-for="file in data?.data ?? []"
        :key="file.id"
        type="button"
        class="group text-start rounded-lg border border-default bg-default overflow-hidden hover:border-primary focus-visible:outline-2 focus-visible:outline-primary"
        @click="selectedId = file.id"
      >
        <div class="aspect-square bg-elevated flex items-center justify-center">
          <img v-if="file.is_image" :src="file.url" :alt="file.name" loading="lazy" class="size-full object-cover">
          <div v-else class="flex flex-col items-center gap-2 text-muted">
            <UIcon name="i-lucide-file" class="size-10" />
            <span class="text-xs font-semibold">{{ extension(file) }}</span>
          </div>
        </div>
        <div class="p-2 space-y-1">
          <div class="text-sm font-medium truncate group-hover:text-primary" :title="file.name">{{ file.name }}</div>
          <div class="flex items-center justify-between gap-2 text-xs text-muted">
            <span>{{ size(file.size) }}</span>
            <UBadge v-if="file.usage_count" :label="$t('library.usedTimes', file.usage_count)" color="success" variant="subtle" size="sm" />
            <UBadge v-else-if="file.kept" :label="$t('library.kept')" color="info" variant="subtle" size="sm" />
            <UBadge v-else :label="$t('library.free')" color="neutral" variant="subtle" size="sm" />
          </div>
        </div>
      </button>
    </div>
    <UCard v-if="!pending && !data?.data.length" :ui="{ body: 'p-0 sm:p-0' }">
      <EmptyState :text="search || kind !== 'all' || usage !== 'all' ? $t('library.noMatches') : $t('library.empty')" />
    </UCard>

    <div v-if="(data?.meta.total_pages ?? 0) > 1" class="mt-6 flex justify-center">
      <UPagination v-model:page="page" :total="data?.meta.total_items ?? 0" :items-per-page="limit" />
    </div>

    <MediaTypesDialog v-if="isAdmin" v-model:open="typesOpen" />
    <MediaDetail v-model:id="selectedId" @changed="refresh()" @deleted="refresh()" />
  </div>
</template>
