<script setup lang="ts">
// Media library › File types (admins): which types may be uploaded at all - by group, searchable, SVG to switch on
type MediaType = { type: string, extension: string, group: 'image' | 'document' | 'archive' | 'audio' | 'video', optional: boolean }
const open = defineModel<boolean>('open', { default: false })
const { t } = useI18n()
const { submit, saving, errors } = useSubmit()

const types = ref<MediaType[]>([])
const allowed = ref<string[]>([])
const search = ref('')
watch(open, async (value) => {
  if (!value) return
  search.value = ''
  const res = await useApi()<{ data: { types: MediaType[], allowed: string[] } }>('/admin/settings/media-types')
  types.value = res.data.types
  allowed.value = [...res.data.allowed]
}, { immediate: true })

const GROUPS = ['image', 'document', 'archive', 'audio', 'video'] as const
// "svg", "image/", "pdf", "Bilder" … - extension, MIME type or the name of the group
const matches = (item: MediaType) => {
  const query = search.value.trim().toLowerCase().replace(/^\./, '')
  return !query || item.extension.includes(query) || item.type.includes(query) || t(`mediaTypes.groups.${item.group}`).toLowerCase().includes(query)
}
const byGroup = computed(() => GROUPS
  .map(group => ({ group, all: types.value.filter(type => type.group === group), items: types.value.filter(type => type.group === group && matches(type)) }))
  .filter(g => g.items.length))
const isAllowed = (type: string) => allowed.value.includes(type)
const toggle = (type: string, on: boolean) => { allowed.value = on ? [...new Set([...allowed.value, type])] : allowed.value.filter(item => item !== type) }
const toggleAll = (items: MediaType[], on: boolean) => items.forEach(item => toggle(item.type, on))
const groupState = (items: MediaType[]) => items.every(item => isAllowed(item.type)) ? true : items.some(item => isAllowed(item.type)) ? 'indeterminate' as const : false

async function save() {
  if (await submit(() => useApi()('/admin/settings/media-types', { method: 'PUT', body: { allowed: allowed.value } }), t('common.saved')) !== null) open.value = false
}
</script>

<template>
  <UModal v-model:open="open" :title="$t('mediaTypes.title')" :description="$t('mediaTypes.help')" :ui="{ content: 'sm:max-w-3xl', body: 'p-0 sm:p-0' }">
    <template #body>
      <div class="sticky top-0 z-10 flex flex-wrap items-center gap-3 border-b border-default bg-default px-4 py-3 sm:px-6">
        <UInput v-model="search" icon="i-lucide-search" :placeholder="$t('mediaTypes.search')" class="min-w-56 flex-1" autofocus />
        <span class="text-sm text-muted">{{ $t('mediaTypes.count', { allowed: allowed.length, total: types.length }) }}</span>
      </div>

      <div class="max-h-[60vh] space-y-6 overflow-y-auto px-4 py-4 sm:px-6">
        <section v-for="{ group, all, items } in byGroup" :key="group">
          <div class="mb-2 flex items-center justify-between gap-3">
            <h3 class="font-semibold">
              {{ $t(`mediaTypes.groups.${group}`) }}
              <span class="ms-1 text-sm font-normal text-muted">{{ all.filter(item => isAllowed(item.type)).length }} / {{ all.length }}</span>
            </h3>
            <UCheckbox
              :model-value="groupState(items)"
              :label="search ? $t('mediaTypes.allShown') : $t('mediaTypes.all')"
              @update:model-value="toggleAll(items, $event === true)"
            />
          </div>
          <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <label
              v-for="item in items"
              :key="item.type"
              :for="`media-type-${item.type}`"
              class="flex min-w-0 cursor-pointer items-center gap-3 rounded-md border px-3 py-2 transition-colors"
              :class="isAllowed(item.type) ? 'border-primary/40 bg-primary/5' : 'border-default hover:bg-elevated/50'"
            >
              <UCheckbox :id="`media-type-${item.type}`" :model-value="isAllowed(item.type)" @update:model-value="toggle(item.type, !!$event)" />
              <span class="min-w-0 flex-1">
                <span class="flex items-center gap-1.5">
                  <span class="font-mono text-sm font-semibold">.{{ item.extension }}</span>
                  <UBadge v-if="item.optional" :label="$t('mediaTypes.optional')" color="warning" variant="subtle" size="xs" />
                </span>
                <span class="block truncate font-mono text-xs text-muted" :title="item.type">{{ item.type }}</span>
              </span>
            </label>
          </div>
          <p v-if="items.some(item => item.optional)" class="mt-2 text-xs text-muted">{{ $t('mediaTypes.svgHelp') }}</p>
        </section>
        <p v-if="!byGroup.length" class="py-8 text-center text-sm text-muted">{{ $t('mediaTypes.nothing', { search }) }}</p>
        <p v-if="errors.allowed" class="text-sm text-error">{{ errors.allowed }}</p>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton color="neutral" variant="ghost" :label="$t('common.cancel')" @click="open = false" />
        <UButton icon="i-lucide-save" :loading="saving" :label="$t('common.save')" @click="save" />
      </div>
    </template>
  </UModal>
</template>
