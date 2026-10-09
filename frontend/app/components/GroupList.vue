<script setup lang="ts">
import type { FieldGroupDef } from '~/types/api'

// Field groups (kind "group": reusable fields like SEO, for group fields) or blocks (kind "block":
// the items of block lists - hero, text, image …). Both work alike; each is chosen only in its place.
const props = defineProps<{ kind: 'group' | 'block' }>()
const { t } = useI18n()
const { project } = useAuth()
// Texts of the kind: groups.* or blockTypes.*
const k = (key: string, params?: Record<string, unknown>) => t(`${props.kind === 'block' ? 'blockTypes' : 'groups'}.${key}`, params ?? {})
const base = computed(() => props.kind === 'block' ? '/admin/blocks' : '/admin/groups')

const { data } = await useAsyncData(`admin-groups-${props.kind}`, () => useApi()<{ data: FieldGroupDef[] }>('/admin/groups', { query: { kind: props.kind } }))
const groups = computed(() => data.value?.data ?? [])
// By category; the categories in use as suggestions
const sections = computed(() => byCategory(groups.value))
// Categories collapsed by the viewer - remembered per list in the browser
const storageKey = `excellent.collapsed.${props.kind}`
const collapsed = ref<string[]>([])
onMounted(() => {
  try { collapsed.value = JSON.parse(localStorage.getItem(storageKey) ?? '[]') } catch { /* no storage */ }
})
const sectionKey = (category: string | null) => category ?? '-'
function toggleSection(category: string | null) {
  const key = sectionKey(category)
  collapsed.value = collapsed.value.includes(key) ? collapsed.value.filter(k => k !== key) : [...collapsed.value, key]
  try { localStorage.setItem(storageKey, JSON.stringify(collapsed.value)) } catch { /* no storage */ }
}
const categories = computed(() => categoriesOf(groups.value))

const open = ref(false)
const form = reactive({ label: '', name: '', category: '', fieldLabel: 'Title', fieldName: 'title' })
const { submit, saving, errors } = useSubmit()
watch(() => form.label, (label) => { form.name = slugify(label, 40).replace(/-/g, '_') })

async function create() {
  const res = await submit(() => useApi()<{ data: FieldGroupDef }>('/admin/groups', {
    method: 'POST',
    body: { label: form.label, name: form.name, kind: props.kind, category: form.category, fields: [{ name: form.fieldName, label: form.fieldLabel, type: 'string' }] }
  }), k('created'))
  if (res) {
    open.value = false
    await navigateTo(`${base.value}/${res.data.id}`)
  }
}
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <AppPageHeader :title="kind === 'block' ? $t('nav.blocks') : $t('nav.groups')" :subtitle="k('subtitle', { project: project?.name ?? '' })">
      <template #actions>
        <UButton icon="i-lucide-plus" :label="k('new')" @click="open = true" />
      </template>
    </AppPageHeader>

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <ul class="divide-y divide-default">
        <template v-for="section in sections" :key="section.category ?? '-'">
          <li v-if="sections.length > 1 || section.category">
            <button type="button" class="flex w-full items-center gap-2 bg-elevated/40 px-4 py-1.5 text-start text-xs font-semibold uppercase tracking-wide text-muted hover:text-default" :aria-expanded="!collapsed.includes(sectionKey(section.category))" @click="toggleSection(section.category)">
              <UIcon :name="collapsed.includes(sectionKey(section.category)) ? 'i-lucide-chevron-right' : 'i-lucide-chevron-down'" class="size-4" />
              <span class="flex-1">{{ section.category ?? $t('groups.noCategory') }}</span>
              <span class="font-normal normal-case">{{ section.items.length }}</span>
            </button>
          </li>
          <li v-for="group in collapsed.includes(sectionKey(section.category)) ? [] : section.items" :key="group.id">
            <NuxtLink :to="`${base}/${group.id}`" class="group flex items-center gap-4 px-4 py-3 hover:bg-elevated/40">
              <UIcon :name="kind === 'block' ? 'i-lucide-boxes' : 'i-lucide-layers'" class="size-5 text-muted shrink-0" />
              <div class="flex-1 min-w-0">
                <div class="font-medium truncate group-hover:text-primary">{{ group.label }} <span class="font-mono text-sm text-muted">{{ group.name }}</span></div>
                <div class="text-sm text-muted truncate">{{ group.fields.map(f => f.label).join(', ') }}</div>
              </div>
              <UBadge :label="group.usage_count ? $t('groups.used', group.usage_count) : $t('groups.unused')" :color="group.usage_count ? 'success' : 'neutral'" variant="subtle" />
            </NuxtLink>
          </li>
        </template>
      </ul>
      <EmptyState v-if="!groups.length" :icon="kind === 'block' ? 'i-lucide-boxes' : 'i-lucide-layers'" :text="k('empty')" />
    </UCard>

    <UModal v-model:open="open" :title="k('new')">
      <template #body>
        <form class="space-y-4" @submit.prevent="create">
          <div class="grid grid-cols-2 gap-4">
            <UFormField :label="$t('common.name')" :error="errors.label" required><UInput v-model="form.label" :placeholder="kind === 'block' ? 'Hero' : 'SEO'" autofocus class="w-full" /></UFormField>
            <UFormField :label="$t('common.technicalName')" :error="errors.name" required><UInput v-model="form.name" class="font-mono w-full" /></UFormField>
            <UFormField :label="$t('groups.category')" :error="errors.category" class="col-span-2">
              <UInputMenu v-model="form.category" :items="categories" create-item class="w-full" :placeholder="$t('groups.noCategory')" @create="(item: string) => { form.category = item }" />
            </UFormField>
            <UFormField :label="$t('groups.firstField')" :error="errors['fields']"><UInput v-model="form.fieldLabel" class="w-full" /></UFormField>
            <UFormField :label="$t('common.technicalName')"><UInput v-model="form.fieldName" class="font-mono w-full" /></UFormField>
          </div>
          <p class="text-sm text-muted">{{ k('moreFieldsLater') }}</p>
        </form>
      </template>
      <template #footer>
        <div class="flex justify-end gap-3 w-full">
          <UButton color="neutral" variant="outline" :label="$t('common.cancel')" @click="open = false" />
          <UButton icon="i-lucide-plus" :loading="saving" :label="$t('common.create')" @click="create" />
        </div>
      </template>
    </UModal>
  </div>
</template>
