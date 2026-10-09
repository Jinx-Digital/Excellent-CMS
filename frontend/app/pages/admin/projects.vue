<script setup lang="ts">
import type { MediaStorageOption, Project } from '~/types/api'

// Projects: each with its own entities (tables <prefix><name>), API clients, media and content API
// under /api/v1/<project>/content.
definePageMeta({ admin: true })
const { t, locale } = useI18n()
useHead({ title: () => t('nav.projects') })
const { project: current, loadSession, switchProject } = useAuth()
const { number } = useFormat()

const { data, refresh } = await useAsyncData('admin-projects', () => useApi()<{ data: Project[] }>('/admin/projects'))
const projects = computed(() => data.value?.data ?? [])
const { data: storageData } = await useAsyncData('admin-media-storages', () => useApi()<{ data: MediaStorageOption[] }>('/admin/media-storages'))
const storages = computed(() => storageData.value?.data ?? [])
// Storage of new uploads: every storage once, the default one (always "local" unless the .env says
// otherwise) marked - picking it stores no own choice, so the project follows the default
const defaultStorage = computed(() => storages.value.find(s => s.default)?.name ?? 'local')
const storageLabel = (s: MediaStorageOption) => `${s.label} · ${s.type.toUpperCase()} · ${s.private ? t('projects.storagePrivate') : t('projects.storagePublic')}`
const storageItems = computed(() => storages.value.map(s => ({ value: s.name, label: s.default ? t('projects.storageDefault', { storage: storageLabel(s) }) : storageLabel(s) })))
const pickedStorage = computed(() => storages.value.find(s => s.name === form.media_storage) ?? storages.value.find(s => s.default))

const open = ref(false)
const editing = ref<Project | null>(null)
const form = reactive({ name: '', slug: '', table_prefix: '', description: '', languages: [] as string[], media_storage: '' })
const languageItems = computed(() => [...new Set([...LANGUAGES, ...form.languages])].map(code => ({ value: code, label: languageName(code, locale.value) })))
// Languages that are removed lose their texts - asked before saving
const removedLanguages = computed(() => (editing.value?.languages ?? []).filter(code => !form.languages.includes(code)))
const confirmRemoval = ref(false)
function setDefault(code: string) {
  form.languages = [code, ...form.languages.filter(other => other !== code)]
}
const { submit, saving, errors } = useSubmit()

function edit(project: Project | null) {
  editing.value = project
  Object.assign(form, project
    ? { name: project.name, slug: project.slug, table_prefix: project.table_prefix, description: project.description ?? '', languages: [...project.languages], media_storage: project.media_storage ?? defaultStorage.value }
    : { name: '', slug: '', table_prefix: '', description: '', languages: ['en'], media_storage: defaultStorage.value })
  confirmRemoval.value = false
  errors.value = {}
  open.value = true
}

// New projects: technical name and prefix follow the name
watch(() => form.name, (name) => {
  if (editing.value) return
  form.slug = slugify(name, 40).replace(/-/g, '_')
  form.table_prefix = `${form.slug.replace(/_/g, '').slice(0, 18) || 'p'}_`
})

async function save() {
  if (removedLanguages.value.length && !confirmRemoval.value) {
    confirmRemoval.value = true
    return
  }
  const body = { ...form, media_storage: form.media_storage === defaultStorage.value ? '' : form.media_storage }
  const res = await submit(() => editing.value
    ? useApi()<{ data: Project }>(`/admin/projects/${editing.value.id}`, { method: 'PUT', body })
    : useApi()<{ data: Project }>('/admin/projects', { method: 'POST', body }), editing.value ? t('projects.saved') : t('projects.created'))
  if (res) {
    open.value = false
    await refresh()
    await loadSession()
  }
}

async function remove(project: Project) {
  if (await submit(() => useApi()(`/admin/projects/${project.id}`, { method: 'DELETE' }), t('projects.deleted')) !== null) {
    await refresh()
    if (current.value?.id === project.id) await switchProject(projects.value[0]!.slug)
    else await loadSession()
  }
}
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <AppPageHeader :title="$t('nav.projects')" :subtitle="$t('projects.subtitle')">
      <template #actions>
        <UButton icon="i-lucide-plus" :label="$t('projects.new')" @click="edit(null)" />
      </template>
    </AppPageHeader>

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <ul class="divide-y divide-default">
        <li v-for="p in projects" :key="p.id" class="flex items-center gap-4 px-4 py-3 hover:bg-elevated/40">
          <UIcon :name="p.is_global ? 'i-lucide-globe' : 'i-lucide-folder-kanban'" class="size-5 text-muted shrink-0" />
          <button type="button" class="flex-1 min-w-0 text-start group" @click="edit(p)">
            <div class="font-medium group-hover:text-primary truncate">
              {{ p.name }}
              <UBadge v-if="current?.id === p.id" :label="$t('projects.current')" color="primary" variant="subtle" size="sm" class="ms-1" />
              <UBadge v-if="p.is_global" :label="$t('projects.shared')" color="neutral" variant="subtle" size="sm" class="ms-1" />
            </div>
            <div class="text-sm text-muted truncate">
              <span class="font-mono">/api/v1/{{ p.slug }}/content</span> · {{ $t('projects.tables') }} <span class="font-mono">_{{ p.table_prefix }}*</span> · {{ $t('projects.entities', { count: number(p.entity_count ?? 0) }, p.entity_count ?? 0) }}<template v-if="p.languages.length"> · {{ p.languages.join(', ') }}</template>
            </div>
          </button>
          <UButton v-if="current?.id !== p.id" size="sm" color="neutral" variant="outline" :label="$t('projects.open')" @click="switchProject(p.slug)" />
          <ConfirmButton
            v-if="!p.is_global && !p.entity_count && projects.filter(other => !other.is_global).length > 1"
            :label="$t('common.delete')"
            icon="i-lucide-trash-2"
            variant="ghost"
            size="sm"
            :question="$t('projects.deleteQuestion', { project: p.name })"
            @confirm="remove(p)"
          />
        </li>
      </ul>
    </UCard>

    <UModal v-model:open="open" :title="editing ? $t('projects.titleOf', { project: editing.name }) : $t('projects.new')">
      <template #body>
        <form class="space-y-4" @submit.prevent="save">
          <UFormField :label="$t('common.name')" :error="errors.name" required><UInput v-model="form.name" autofocus class="w-full" /></UFormField>
          <div class="grid grid-cols-2 gap-4">
            <UFormField :label="$t('common.technicalNameApi')" :help="`/api/v1/${form.slug || '…'}/content`" :error="errors.slug" required>
              <UInput v-model="form.slug" class="font-mono w-full" />
            </UFormField>
            <UFormField :label="$t('projects.prefix')" :help="editing?.entity_count ? $t('projects.prefixLocked') : $t('projects.prefixExample', { table: `${form.table_prefix || 'shop_'}products` })" :error="errors.table_prefix" required>
              <UInput v-model="form.table_prefix" class="font-mono w-full" :disabled="!!editing?.entity_count" />
            </UFormField>
          </div>
          <UFormField :label="$t('projects.languages')" :help="$t('projects.languagesHelp')" :error="errors.languages">
            <USelectMenu v-model="form.languages" :items="languageItems" value-key="value" multiple create-item :placeholder="$t('projects.oneLanguage')" class="w-full" @create="(code: string) => form.languages.push(code.toLowerCase())" />
          </UFormField>
          <UFormField v-if="form.languages.length > 1" :label="$t('projects.defaultLanguage')" :help="$t('projects.defaultLanguageHelp')">
            <USelect :model-value="form.languages[0]" :items="form.languages.map(code => ({ value: code, label: languageName(code, locale) }))" class="w-full" @update:model-value="setDefault($event as string)" />
          </UFormField>
          <UAlert
            v-if="confirmRemoval && removedLanguages.length"
            color="warning"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            :title="$t('projects.languagesRemoved', { languages: removedLanguages.map(code => languageName(code, locale)).join(', ') })"
            :description="$t('projects.confirmBySaving')"
          />
          <UFormField :label="$t('projects.storage')" :help="$t('projects.storageHelp')" :error="errors.media_storage">
            <template #hint>
              <ULink to="/admin/storages" class="text-xs text-primary" @click="open = false">{{ $t('projects.manageStorages') }}</ULink>
            </template>
            <USelect v-model="form.media_storage" :items="storageItems" class="w-full" />
          </UFormField>
          <UAlert
            v-if="pickedStorage && !pickedStorage.private && storages.length > 1"
            color="neutral"
            variant="subtle"
            icon="i-lucide-info"
            :description="$t('projects.storagePublicHint')"
          />
          <UFormField :label="$t('common.description')"><UTextarea v-model="form.description" autoresize :rows="2" class="w-full" /></UFormField>
        </form>
      </template>
      <template #footer>
        <div class="flex justify-end gap-3 w-full">
          <UButton color="neutral" variant="outline" :label="$t('common.cancel')" @click="open = false" />
          <UButton icon="i-lucide-save" :loading="saving" :label="$t('common.save')" @click="save" />
        </div>
      </template>
    </UModal>
  </div>
</template>
