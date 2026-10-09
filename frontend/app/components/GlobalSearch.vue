<script setup lang="ts">
import type { CommandPaletteGroup, CommandPaletteItem } from '@nuxt/ui'

// Search across all entities of the project (⌘K / Ctrl+K): the best records per entity, the
// entities with the most matches first. "Only this one" searches one entity: "in:pages summer".
interface SearchGroup {
  entity: { slug: string, name: string }
  total: number
  records: { id: string, label: string, draft: boolean }[]
}

const open = useState('global-search-open', () => false)
const { t } = useI18n()
const route = useRoute()

const term = ref('')
const loading = ref(false)
const result = ref<{ query: string, in: string | null, groups: SearchGroup[] } | null>(null)
let request = 0

watchDebounced(term, async (value) => {
  const search = value.trim()
  if (!search) {
    result.value = null
    return
  }
  const current = ++request
  loading.value = true
  try {
    const res = await useApi()<{ data: { query: string, in: string | null, groups: SearchGroup[] } }>('/search', { query: { s: search } })
    if (current === request) result.value = res.data
  } catch {
    if (current === request) result.value = { query: search, in: null, groups: [] }
  } finally {
    if (current === request) loading.value = false
  }
}, { debounce: 250 })

const groups = computed<CommandPaletteGroup<CommandPaletteItem>[]>(() => {
  const data = result.value
  if (!data) return []
  const list: CommandPaletteGroup<CommandPaletteItem>[] = data.groups.map(group => ({
    id: group.entity.slug,
    label: `${group.entity.name} · ${group.total}`,
    ignoreFilter: true,
    items: [
      ...group.records.map(record => ({
        label: record.label,
        suffix: record.draft ? t('record.draft') : undefined,
        icon: 'i-lucide-file-text',
        to: `/entities/${group.entity.slug}/${record.id}`
      })),
      // More matches than shown: search this entity only
      ...(!data.in && group.total > group.records.length
        ? [{ label: t('search.onlyThis', { entity: group.entity.name, count: group.total }), icon: 'i-lucide-list-filter', onSelect: (event: Event) => { event.preventDefault(); term.value = `in:${group.entity.slug} ${data.query}` } }]
        : [])
    ]
  }))
  if (data.in) {
    list.unshift({
      id: 'all',
      ignoreFilter: true,
      items: [{ label: t('search.allEntities'), icon: 'i-lucide-arrow-left', onSelect: (event: Event) => { event.preventDefault(); term.value = data.query } }]
    })
  }
  return list
})

// ⌘K / Ctrl+K anywhere; a record opened from the list closes it
useEventListener('keydown', (event: KeyboardEvent) => {
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    open.value = !open.value
  }
})
watch(() => route.fullPath, () => { open.value = false })
watch(open, (value) => { if (!value) request++ })
</script>

<template>
  <UModal v-model:open="open" :ui="{ content: 'sm:max-w-2xl' }" :title="$t('search.title')" :description="$t('search.description')">
    <template #content>
      <UCommandPalette
        v-model:search-term="term"
        :groups="groups"
        :loading="loading"
        :placeholder="$t('search.placeholder')"
        close
        class="h-[min(70vh,560px)]"
        @update:open="open = $event"
      >
        <template #empty>
          <div class="px-4 py-10 text-center text-sm text-muted">
            <template v-if="!term.trim()">
              <UIcon name="i-lucide-search" class="mx-auto mb-2 size-6" />
              <p>{{ $t('search.hint') }}</p>
              <p class="mt-1 text-xs text-dimmed">{{ $t('search.hintIn') }}</p>
            </template>
            <p v-else-if="!loading">{{ $t('search.nothing', { term: term.trim() }) }}</p>
          </div>
        </template>
      </UCommandPalette>
    </template>
  </UModal>
</template>
