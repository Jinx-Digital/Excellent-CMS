<script setup lang="ts">
// Search index of the project: stop words for all languages and per language (not indexed, ignored in searches), status per entity,
// rebuild or clear - all entities or one. A stale index is searched in the columns until rebuilt.
definePageMeta({ admin: true })
const { t } = useI18n()
useHead({ title: () => t('nav.search') })
const format = useFormat()

interface IndexStatus {
  entity: string
  slug: string
  name: string
  status: 'ready' | 'stale'
  records: number
  indexed: number
  built_at: string | null
}
// Stop words per language: "" = the default language, else the code of a translation
interface Overview { stopwords: Record<string, string[]>, entities: IndexStatus[] }

const { data, refresh } = await useAsyncData('admin-search-index', () => useApi()<{ data: Overview }>('/admin/search-index'))
const overview = computed(() => data.value?.data)
const { submit, saving, errors } = useSubmit()
const busy = ref<string | null>(null)

const { project } = useAuth()
// The languages of the project - the first is the default one ("")
// "*": for all languages (e.g. numbers) - with several languages, then one field per language
const stopwordLanguages = computed(() => {
  const languages = project.value?.languages ?? []
  return [
    { key: '*', label: t('searchIndex.stopwordsAll') },
    ...(languages.length ? languages.map((code, index) => ({ key: index === 0 ? '' : code, label: t('searchIndex.stopwordsIn', { language: code.toUpperCase() }) })) : [{ key: '', label: t('searchIndex.stopwordsLanguage') }]),
  ]
})
const stopwords = reactive<Record<string, string>>({})
const normalize = (text: string) => text.split(/[\s,;]+/).filter(Boolean).map(w => w.toLowerCase()).sort().join(' ')
watch(() => overview.value?.stopwords, (value) => {
  for (const { key } of stopwordLanguages.value) stopwords[key] = (value?.[key] ?? []).join(' ')
}, { immediate: true })
const stopwordsChanged = computed(() => stopwordLanguages.value.some(({ key }) => normalize(stopwords[key] ?? '') !== normalize((overview.value?.stopwords?.[key] ?? []).join(' '))))

async function saveStopwords() {
  const res = await submit(() => useApi()<{ data: Overview }>('/admin/search-index/stopwords', { method: 'PUT', body: { stopwords: { ...stopwords } } }), t('searchIndex.stopwordsSaved'))
  if (res) data.value = res
}

async function run(action: 'rebuild' | 'clear', entity?: string) {
  busy.value = `${action}:${entity ?? '*'}`
  const res = await submit(() => useApi()<{ data: Overview }>(`/admin/search-index/${action}`, { method: 'POST', body: entity ? { entity } : {} }), action === 'rebuild' ? t('searchIndex.rebuilt') : t('searchIndex.cleared'))
  busy.value = null
  if (res) data.value = res
  else await refresh()
}

const staleCount = computed(() => (overview.value?.entities ?? []).filter(e => e.status === 'stale').length)
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <AppPageHeader :title="$t('nav.search')" :subtitle="$t('searchIndex.subtitle')">
      <template #actions>
        <ConfirmButton :label="$t('searchIndex.clearAll')" icon="i-lucide-eraser" variant="ghost" color="neutral" :question="$t('searchIndex.clearQuestion')" @confirm="run('clear')" />
        <UButton icon="i-lucide-refresh-cw" :loading="busy === 'rebuild:*'" :label="$t('searchIndex.rebuildAll')" @click="run('rebuild')" />
      </template>
    </AppPageHeader>

    <UAlert v-if="staleCount" color="warning" variant="subtle" icon="i-lucide-triangle-alert" :title="$t('searchIndex.staleTitle', staleCount)" :description="$t('searchIndex.staleText')" />

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <template #header>
        <h2 class="font-semibold">{{ $t('searchIndex.entities') }}</h2>
      </template>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-elevated/50 text-left">
            <tr>
              <th class="px-4 py-3">Entity</th>
              <th class="px-4 py-3">{{ $t('searchIndex.status') }}</th>
              <th class="px-4 py-3 text-right">{{ $t('searchIndex.indexed') }}</th>
              <th class="px-4 py-3">{{ $t('searchIndex.builtAt') }}</th>
              <th class="px-4 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-default">
            <tr v-for="entity in overview?.entities ?? []" :key="entity.entity">
              <td class="px-4 py-3 font-medium">{{ entity.name }} <span class="font-mono text-xs text-muted">{{ entity.slug }}</span></td>
              <td class="px-4 py-3">
                <UBadge v-if="entity.status === 'ready'" :label="$t('searchIndex.ready')" color="success" variant="subtle" icon="i-lucide-check" />
                <UBadge v-else :label="$t('searchIndex.stale')" color="warning" variant="subtle" icon="i-lucide-clock-alert" />
              </td>
              <td class="px-4 py-3 text-right tabular-nums">{{ format.number(entity.indexed) }} / {{ format.number(entity.records) }}</td>
              <td class="px-4 py-3 text-muted whitespace-nowrap">{{ entity.built_at ? format.relative(entity.built_at) : '–' }}</td>
              <td class="px-4 py-3">
                <div class="flex justify-end gap-1">
                  <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-refresh-cw" :loading="busy === `rebuild:${entity.slug}`" :label="$t('searchIndex.rebuild')" @click="run('rebuild', entity.slug)" />
                  <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-eraser" :loading="busy === `clear:${entity.slug}`" :aria-label="$t('searchIndex.clear')" @click="run('clear', entity.slug)" />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        <EmptyState v-if="!overview?.entities.length" :text="$t('schema.noEntities')" />
      </div>
    </UCard>

    <UCard>
      <template #header>
        <h2 class="font-semibold">{{ $t('searchIndex.stopwords') }}</h2>
        <p class="text-sm text-muted">{{ $t('searchIndex.stopwordsHelp') }}</p>
      </template>
      <div class="space-y-4">
        <UFormField v-for="language in stopwordLanguages" :key="language.key" :label="language.label" :error="errors.stopwords">
          <UTextarea v-model="stopwords[language.key]" :rows="3" autoresize class="font-mono w-full" :placeholder="$t('searchIndex.stopwordsExample')" />
        </UFormField>
      </div>
      <div class="mt-3 flex items-center justify-between gap-3">
        <p class="text-xs text-muted">{{ $t('searchIndex.stopwordsNote') }}</p>
        <UButton icon="i-lucide-save" :loading="saving && !busy" :disabled="!stopwordsChanged" :label="$t('common.save')" @click="saveStopwords" />
      </div>
    </UCard>
  </div>
</template>
