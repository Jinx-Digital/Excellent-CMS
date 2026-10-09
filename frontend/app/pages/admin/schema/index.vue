<script setup lang="ts">
import type { Entity } from '~/types/api'

definePageMeta({ admin: true })
const { t } = useI18n()
useHead({ title: () => t('nav.schema') })
const { number } = useFormat()
const { loadSession, project, projects, switchProject } = useAuth()

const { data, refresh } = await useAsyncData('admin-entities', () => useApi()<{ data: Entity[] }>('/admin/entities'))
// Entities of the area "Global" are edited there - here they are only listed
const own = computed(() => (data.value?.data ?? []).filter(e => !e.global || project.value?.is_global))
const shared = computed(() => project.value?.is_global ? [] : (data.value?.data ?? []).filter(e => e.global))
const globalProject = computed(() => projects.value.find(p => p.is_global))

// New entity by hand (usually entities come from an import)
const open = ref(false)
const form = reactive({ name: '', slug: '', access: 'public', trash: true, fieldName: 'name', fieldLabel: 'Name' })
const { submit, saving, errors } = useSubmit()
watch(() => form.name, (name) => {
  form.slug = name.toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40)
})

async function create() {
  const res = await submit(() => useApi()<{ data: Entity }>('/admin/entities', {
    method: 'POST',
    body: { name: form.name, slug: form.slug, access: form.access, trash: form.trash, label_field: form.fieldName, fields: [{ name: form.fieldName, label: form.fieldLabel, type: 'string', required: true }] }
  }), t('schema.created'))
  if (res) {
    open.value = false
    await loadSession()
    await navigateTo(`/admin/schema/${res.data.id}`)
  }
}

const drag = useDragSort(move)
async function move(from: number, to: number) {
  const ids = moved(own.value.map(e => e.id), from, to)
  await submit(() => useApi()('/admin/entities/order', { method: 'POST', body: { ids } }), '')
  await refresh()
  await loadSession()
}
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <AppPageHeader
      :title="$t('nav.schema')"
      :subtitle="project?.is_global ? $t('schema.globalSubtitle') : $t('schema.listSubtitle')"
    >
      <template #actions>
        <UButton to="/import" icon="i-lucide-file-up" color="neutral" variant="outline" :label="$t('schema.fromFile')" />
        <UModal v-model:open="open" :title="$t('schema.new')">
          <UButton icon="i-lucide-plus" :label="$t('schema.new')" />
          <template #body>
            <div class="space-y-4">
              <UFormField :label="$t('common.name')" :error="errors.name" required><UInput v-model="form.name" autofocus /></UFormField>
              <UFormField :label="$t('common.technicalNameApi')" :error="errors.slug" required><UInput v-model="form.slug" class="font-mono" /></UFormField>
              <UFormField :label="$t('schema.accessShort')" :error="errors.access">
                <USelect v-model="form.access" :items="[{ value: 'public', label: $t('access.public') }, { value: 'oauth', label: $t('access.oauthOnly') }]" />
              </UFormField>
              <div class="grid grid-cols-2 gap-4">
                <UFormField :label="$t('groups.firstField')" :error="errors['fields.0.label']"><UInput v-model="form.fieldLabel" /></UFormField>
                <UFormField :label="$t('common.technicalName')" :error="errors['fields.0.name']"><UInput v-model="form.fieldName" class="font-mono" /></UFormField>
              </div>
              <USwitch id="new-entity-trash" v-model="form.trash" :label="$t('trash.title')" :description="$t('schema.trashShort')" />
            </div>
          </template>
          <template #footer>
            <div class="flex justify-end gap-3 w-full">
              <UButton color="neutral" variant="outline" :label="$t('common.cancel')" @click="open = false" />
              <UButton icon="i-lucide-plus" :loading="saving" :label="$t('common.create')" @click="create" />
            </div>
          </template>
        </UModal>
      </template>
    </AppPageHeader>

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <EmptyState v-if="!own.length" icon="i-lucide-blocks" :text="$t('schema.noEntities')" />
      <ul v-else class="divide-y divide-default">
        <li v-for="(entity, index) in own" :key="entity.id" class="flex items-center gap-4 px-4 py-3 hover:bg-elevated/40" v-bind="drag.row(index, own.length > 1)" :class="drag.rowClass(index)">
          <DragHandle v-if="own.length > 1" @move="step => drag.move(index, index + step, own.length)" />
          <NuxtLink :to="`/admin/schema/${entity.id}`" class="flex-1 min-w-0 group">
            <div class="font-medium group-hover:text-primary truncate">{{ entity.name }}</div>
            <div class="text-sm text-muted font-mono truncate">{{ entity.slug }} · {{ $t('schema.meta', { fields: entity.fields.length, records: number(entity.record_count ?? 0) }) }}</div>
          </NuxtLink>
          <AccessBadge :access="entity.access" />
        </li>
      </ul>
    </UCard>

    <UCard v-if="shared.length || globalProject" :ui="{ body: 'p-0 sm:p-0' }">
      <template #header>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 class="font-semibold flex items-center gap-2"><UIcon name="i-lucide-globe" /> {{ $t('nav.global') }}</h2>
            <p class="text-sm text-muted">{{ $t('schema.globalHelp') }}</p>
          </div>
          <UButton v-if="globalProject" icon="i-lucide-arrow-right-left" color="neutral" variant="outline" :label="$t('schema.toGlobal')" @click="switchProject(globalProject.slug)" />
        </div>
      </template>
      <EmptyState v-if="!shared.length" icon="i-lucide-globe" :text="$t('schema.noGlobal')" />
      <ul v-else class="divide-y divide-default">
        <li v-for="entity in shared" :key="entity.id" class="flex items-center gap-4 px-4 py-3 hover:bg-elevated/40">
          <UIcon name="i-lucide-globe" class="size-5 text-muted shrink-0" />
          <NuxtLink :to="`/entities/${entity.slug}`" class="flex-1 min-w-0 group">
            <div class="font-medium group-hover:text-primary truncate">{{ entity.name }}</div>
            <div class="text-sm text-muted font-mono truncate">{{ entity.slug }} · {{ $t('schema.meta', { fields: entity.fields.length, records: number(entity.record_count ?? 0) }) }}</div>
          </NuxtLink>
          <AccessBadge :access="entity.access" />
        </li>
      </ul>
    </UCard>
  </div>
</template>
