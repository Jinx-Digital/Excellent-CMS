<script setup lang="ts">
import type { PluginField, PluginInfo } from '~/types/api'

// Plugins: uploaded as ZIP, then installed (their migrations run - for all projects), activated and
// deactivated (only a switch: admin plugins of the scope "global" for every project, all others per
// project), uninstalled (migrations back, files removed). Settings and panels come from the plugin.
definePageMeta({ admin: true })
const { t } = useI18n()
const toast = useToast()
useHead({ title: () => t('nav.plugins') })

const { data, refresh } = await useAsyncData('admin-plugins', () => useApi()<{ data: { enabled: boolean, plugins: PluginInfo[] } }>('/admin/plugins'))
const plugins = computed(() => data.value?.data.plugins ?? [])
const enabled = computed(() => data.value?.data.enabled ?? false)
const { submit, saving, errors } = useSubmit()
const { loadSession } = useAuth()
const busy = ref<string | null>(null)

// Upload
const input = ref<HTMLInputElement>()
async function upload(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  ;(event.target as HTMLInputElement).value = ''
  if (!file) return
  const body = new FormData()
  body.append('file', file)
  busy.value = 'upload'
  const res = await submit(() => useApi()<{ data: PluginInfo }>('/admin/plugins/upload', { method: 'POST', body }), t('plugins.uploaded'))
  busy.value = null
  await refresh()
  if (res) open(res.data)
}

const { projects: allProjects, project: currentProject } = useAuth()
// Projects a plugin can be active in (not the area "Global")
const projectItems = computed(() => allProjects.value.filter(p => !p.is_global))
const projectName = (id: string) => allProjects.value.find(p => p.id === id)?.name ?? id
const activeHere = (plugin: PluginInfo) => plugin.scope === 'global' ? plugin.active : !!currentProject.value && plugin.projects.includes(currentProject.value.id)

async function action(plugin: PluginInfo, name: 'install' | 'activate' | 'deactivate' | 'uninstall') {
  busy.value = `${name}:${plugin.name}`
  const messages = { install: plugin.installed ? t('plugins.updated') : t('plugins.installed'), activate: t('plugins.activated'), deactivate: t('plugins.deactivated'), uninstall: t('plugins.uninstalled') }
  await submit(() => name === 'uninstall'
    ? useApi()(`/admin/plugins/${plugin.name}`, { method: 'DELETE' })
    : useApi()(`/admin/plugins/${plugin.name}/${name}`, { method: 'POST' }), messages[name])
  busy.value = null
  await refresh()
  // Steps of plugins in the event editor, pages in the menu, field types
  refreshNuxtData(['plugin-steps', 'plugin-pages'])
  await loadSession()
  if (selected.value?.name === plugin.name) await open(plugins.value.find(p => p.name === plugin.name) ?? null)
  // Updated: its blocks may have new templates (or there are new blocks) - ask
  if (name === 'install' && plugin.installed && plugin.active) syncAsk.value = plugin
}

// Blocks of a plugin: missing ones created, with templates its templates again (see ./yii plugins:sync)
const syncAsk = ref<PluginInfo | null>(null)
const syncing = ref(false)
async function sync(plugin: PluginInfo, templates: boolean) {
  syncing.value = true
  const res = await submit(() => useApi()<{ data: { count: number } }>(`/admin/plugins/${plugin.name}/sync`, { method: 'POST', body: { templates } }), '')
  syncing.value = false
  syncAsk.value = null
  if (res) toast.add({ title: t('plugins.synced', res.data.count), color: 'success', icon: 'i-lucide-refresh-cw' })
}

// Details: settings and panels
const selected = ref<PluginInfo | null>(null)
const settings = reactive<Record<string, string | boolean>>({})
const secrets = reactive<Record<string, string>>({})
async function open(plugin: PluginInfo | null) {
  if (!plugin) {
    selected.value = null
    return
  }
  selected.value = plugin.active ? (await useApi()<{ data: PluginInfo }>(`/admin/plugins/${plugin.name}`)).data : plugin
  for (const key of Object.keys(settings)) delete settings[key]
  for (const key of Object.keys(secrets)) delete secrets[key]
  for (const field of selected.value.fields) {
    if (field.kind === 'secret') secrets[field.key] = selected.value.secrets[field.key]?.env ? `$${selected.value.secrets[field.key]!.env}` : ''
    else settings[field.key] = selected.value.settings[field.key] ?? (field.kind === 'bool' ? false : '')
  }
  errors.value = {}
}
const stored = (key: string) => !!selected.value?.secrets[key]?.stored
// Settings with "when" only show if the others have the values it names
function applies(field: PluginField) {
  return Object.entries(field.when ?? {}).every(([key, wanted]) => {
    const value = settings[key] ?? selected.value?.fields.find(f => f.key === key)?.default
    return (Array.isArray(wanted) ? wanted : [wanted]).includes(value as string | boolean)
  })
}
async function saveSettings() {
  if (!selected.value) return
  const body = { ...settings, ...Object.fromEntries(Object.entries(secrets).map(([key, value]) => [key, value.trim() || (stored(key) ? '' : null)])) }
  const res = await submit(() => useApi()<{ data: PluginInfo }>(`/admin/plugins/${selected.value!.name}/settings`, { method: 'PUT', body }), t('plugins.settingsSaved'))
  if (res) {
    await refresh()
    await open(res.data)
  }
}

// Plugins of projects: the projects they are active in
const projectSelection = ref<string[]>([])
// Its own saving state: the button of the settings must not spin along
const { submit: submitProjects, saving: savingProjects, errors: projectErrors } = useSubmit()
watch(selected, (plugin) => { projectSelection.value = [...(plugin?.projects ?? [])] })
async function saveProjects() {
  if (!selected.value) return
  const name = selected.value.name
  const res = await submitProjects(() => projectSelection.value.length
    ? useApi()<{ data: PluginInfo }>(`/admin/plugins/${name}/activate`, { method: 'POST', body: { projects: projectSelection.value } })
    : useApi()<{ data: PluginInfo }>(`/admin/plugins/${name}/deactivate`, { method: 'POST' }), t('plugins.projectsSaved'))
  if (res) {
    await refresh()
    refreshNuxtData(['plugin-steps', 'plugin-pages'])
    await loadSession()
    await open(res.data)
  }
}

const status = (plugin: PluginInfo) => plugin.problem
  ? { label: t('plugins.broken'), color: 'error' as const }
  : plugin.active && plugin.scope === 'global' ? { label: t('plugins.activeGlobal'), color: 'success' as const }
    : plugin.active ? { label: t('plugins.activeIn', { count: plugin.projects.length }, plugin.projects.length), color: 'success' as const }
    : plugin.installed ? { label: t('plugins.inactive'), color: 'neutral' as const }
      : { label: t('plugins.notInstalled'), color: 'warning' as const }
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <AppPageHeader :title="$t('nav.plugins')" :subtitle="$t('plugins.subtitle')">
      <template #actions>
        <input ref="input" type="file" accept=".zip,application/zip" class="hidden" @change="upload">
        <UButton icon="i-lucide-upload" :loading="busy === 'upload'" :disabled="!enabled" :label="$t('plugins.upload')" @click="input?.click()" />
      </template>
    </AppPageHeader>

    <UAlert v-if="!enabled" color="warning" variant="subtle" icon="i-lucide-plug-zap" :title="$t('plugins.disabledTitle')" :description="$t('plugins.disabledText')" />
    <UAlert v-if="errors.file" color="error" variant="subtle" icon="i-lucide-circle-alert" :title="$t('plugins.uploadFailed')" :description="errors.file" />

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <EmptyState v-if="!plugins.length" icon="i-lucide-puzzle" :text="$t('plugins.empty')" />
      <ul v-else class="divide-y divide-default">
        <!-- The whole row opens the details (projects, settings …); the buttons only do their action -->
        <li v-for="plugin in plugins" :key="plugin.name" class="group flex cursor-pointer flex-wrap items-start gap-x-4 gap-y-3 px-4 py-3 hover:bg-elevated/40" @click="open(plugin)">
          <UIcon name="i-lucide-puzzle" class="mt-1 size-5 shrink-0" :class="plugin.active ? 'text-primary' : 'text-muted'" />
          <button type="button" class="min-w-0 flex-1 text-start">
            <div class="flex flex-wrap items-center gap-2 font-medium group-hover:text-primary">
              {{ plugin.label }}
              <span class="font-mono text-xs text-muted">{{ plugin.name }} {{ plugin.installed_version ?? plugin.version }}</span>
              <UBadge :label="status(plugin).label" :color="status(plugin).color" variant="subtle" size="sm" :title="plugin.scope === 'project' ? plugin.projects.map(projectName).join(', ') : undefined" />
              <UBadge v-if="plugin.scope === 'global'" :label="$t('plugins.scopeGlobal')" color="neutral" variant="subtle" size="sm" icon="i-lucide-shield" />
              <UBadge v-if="plugin.update" :label="$t('plugins.updateTo', { version: plugin.version ?? '' })" color="info" variant="subtle" size="sm" icon="i-lucide-arrow-up-circle" />
            </div>
            <p class="mt-0.5 text-sm text-muted">{{ plugin.description }}</p>
            <p v-if="Object.keys(plugin.requires ?? {}).length || plugin.required_by?.length" class="mt-1 flex flex-wrap gap-1.5 text-xs">
              <UBadge v-for="(constraint, name) in plugin.requires" :key="`r-${name}`" :label="$t('plugins.requiresOne', { plugin: name, version: constraint === '*' ? '' : constraint })" color="neutral" variant="outline" size="sm" icon="i-lucide-link" />
              <UBadge v-for="name in plugin.required_by" :key="`b-${name}`" :label="$t('plugins.requiredBy', { plugin: name })" color="neutral" variant="outline" size="sm" icon="i-lucide-corner-down-right" />
            </p>
            <p v-if="plugin.error" class="mt-1 text-xs text-error">{{ $t('plugins.switchedOff', { error: plugin.error }) }}</p>
            <p v-if="plugin.problem" class="mt-1 text-xs text-error">{{ plugin.problem }}</p>
          </button>
          <div class="flex shrink-0 flex-wrap items-center gap-2" @click.stop>
            <UButton v-if="!plugin.installed || plugin.update || plugin.pending_migrations.length" size="sm" :icon="plugin.installed ? 'i-lucide-arrow-up-circle' : 'i-lucide-download'" :loading="busy === `install:${plugin.name}`" :disabled="!!plugin.problem" :label="plugin.installed ? $t('plugins.update') : $t('plugins.install')" @click="action(plugin, 'install')" />
            <template v-if="plugin.installed">
              <UButton v-if="plugin.scope === 'global' && plugin.active" size="sm" color="neutral" variant="outline" icon="i-lucide-power-off" :loading="busy === `deactivate:${plugin.name}`" :label="$t('plugins.deactivate')" @click="action(plugin, 'deactivate')" />
              <UButton v-else-if="plugin.scope === 'global'" size="sm" color="primary" variant="soft" icon="i-lucide-power" :loading="busy === `activate:${plugin.name}`" :disabled="plugin.update || !!plugin.problem" :label="$t('plugins.activate')" @click="action(plugin, 'activate')" />
              <!-- Plugins of projects: here (the current project) - all projects in the details -->
              <UButton v-else-if="!activeHere(plugin)" size="sm" color="primary" variant="soft" icon="i-lucide-power" :loading="busy === `activate:${plugin.name}`" :disabled="plugin.update || !!plugin.problem || !currentProject || currentProject.is_global" :label="$t('plugins.activateHere')" @click="action(plugin, 'activate')" />
              <ConfirmButton size="sm" icon="i-lucide-trash-2" variant="ghost" :label="$t('plugins.uninstall')" :question="$t('plugins.uninstallQuestion', { plugin: plugin.label })" @confirm="action(plugin, 'uninstall')" />
            </template>
          </div>
        </li>
      </ul>
    </UCard>
    <p class="text-sm text-muted">{{ $t('plugins.note') }}</p>

    <UModal :open="!!selected" :title="selected?.label" :description="selected ? `${selected.name} · ${selected.installed_version ?? selected.version}${selected.author ? ` · ${selected.author}` : ''}` : ''" :ui="{ content: 'sm:max-w-2xl' }" @update:open="(value: boolean) => { if (!value) selected = null }">
      <template #body>
        <div v-if="selected" class="space-y-6">
          <p class="text-sm text-muted">{{ selected.description }}</p>

          <section v-if="selected.installed && selected.scope === 'project'" class="space-y-3">
            <div>
              <h3 class="font-semibold">{{ $t('plugins.activeInProjects') }}</h3>
              <p class="text-sm text-muted">{{ $t('plugins.activeInProjectsHelp') }}</p>
            </div>
            <div class="grid gap-2 sm:grid-cols-2">
              <UCheckbox
                v-for="p in projectItems"
                :id="`plugin-project-${p.id}`"
                :key="p.id"
                :model-value="projectSelection.includes(p.id)"
                :label="p.name"
                :disabled="selected.update || !!selected.problem"
                @update:model-value="projectSelection = $event ? [...projectSelection, p.id] : projectSelection.filter(id => id !== p.id)"
              />
            </div>
            <p v-if="projectErrors.projects" class="text-sm text-error">{{ projectErrors.projects }}</p>
            <div class="flex justify-end">
              <UButton icon="i-lucide-save" :loading="savingProjects" :disabled="[...projectSelection].sort().join() === [...selected.projects].sort().join()" :label="$t('common.save')" @click="saveProjects" />
            </div>
          </section>
          <p v-else-if="selected.installed && selected.scope === 'global'" class="text-sm text-muted">{{ $t('plugins.globalHelp') }}</p>

          <section v-if="selected.installed && selected.fields.length" class="space-y-4">
            <h3 class="font-semibold">{{ $t('plugins.settings') }}</h3>
            <template v-for="field in selected.fields.filter(applies)" :key="field.key">
              <UFormField v-if="field.kind === 'bool'" :help="field.help" :error="errors[field.key]">
                <USwitch :model-value="!!settings[field.key]" :label="field.label" @update:model-value="settings[field.key] = $event" />
              </UFormField>
              <UFormField v-else :label="field.label" :help="field.kind === 'secret' && stored(field.key) ? $t('storages.secretStored') : field.help" :error="errors[field.key]" :required="field.required">
                <USelect v-if="field.kind === 'select'" :model-value="String(settings[field.key] ?? '')" :items="field.options ?? []" class="w-full" @update:model-value="settings[field.key] = String($event)" />
                <UTextarea v-else-if="field.kind === 'textarea'" :model-value="String(settings[field.key] ?? '')" autoresize :rows="3" class="w-full" @update:model-value="settings[field.key] = String($event)" />
                <EnvInput v-else-if="field.kind === 'secret'" v-model="secrets[field.key]" secret :placeholder="stored(field.key) ? '••••••••' : field.placeholder" />
                <EnvInput v-else :model-value="String(settings[field.key] ?? '')" :placeholder="field.placeholder" @update:model-value="settings[field.key] = $event" />
              </UFormField>
            </template>
            <div class="flex justify-end">
              <UButton :loading="saving" icon="i-lucide-save" :label="$t('common.save')" @click="saveSettings" />
            </div>
          </section>

          <section v-if="selected.steps.length" class="space-y-2">
            <h3 class="font-semibold">{{ $t('plugins.adds') }}</h3>
            <ul class="space-y-1 text-sm">
              <li v-for="step in selected.steps" :key="step.type" class="flex items-center gap-2">
                <UIcon :name="step.icon" class="size-4 text-primary" />
                <span>{{ $t('plugins.eventStep') }}: <strong>{{ step.label }}</strong></span>
                <span class="font-mono text-xs text-muted">{{ step.type }}</span>
              </li>
            </ul>
          </section>

          <section v-for="panel in selected.panels ?? []" :key="panel.key" class="space-y-2">
            <h3 class="font-semibold">{{ panel.title }}</h3>
            <p v-if="panel.error" class="text-sm text-error">{{ panel.error }}</p>
            <div v-else-if="panel.rows.length" class="overflow-x-auto rounded-md border border-default">
              <table class="w-full text-sm">
                <thead class="bg-elevated/50 text-left"><tr><th v-for="column in panel.columns" :key="column.key" class="px-3 py-2 font-semibold">{{ column.label }}</th></tr></thead>
                <tbody class="divide-y divide-default">
                  <tr v-for="(row, index) in panel.rows" :key="index"><td v-for="column in panel.columns" :key="column.key" class="px-3 py-2 align-top">{{ row[column.key] ?? '–' }}</td></tr>
                </tbody>
              </table>
            </div>
            <p v-else class="text-sm text-muted">{{ $t('plugins.panelEmpty') }}</p>
          </section>

          <section v-if="selected.installed && selected.active" class="space-y-2">
            <h3 class="font-semibold">{{ $t('plugins.syncTitle') }}</h3>
            <p class="text-sm text-muted">{{ $t('plugins.syncHelp') }}</p>
            <div class="flex flex-wrap gap-2">
              <UButton size="sm" color="neutral" variant="outline" icon="i-lucide-blocks" :loading="syncing" :label="$t('plugins.syncBlocks')" @click="sync(selected, false)" />
              <ConfirmButton size="sm" color="neutral" variant="outline" icon="i-lucide-refresh-cw" :label="$t('plugins.syncTemplates')" :question="$t('plugins.syncTemplatesQuestion')" @confirm="sync(selected, true)" />
            </div>
          </section>

          <section v-if="selected.installed" class="space-y-1 text-xs text-muted">
            <p>{{ $t('plugins.migrations') }}: <span class="font-mono">{{ selected.migrations.join(', ') || '–' }}</span></p>
            <p v-if="selected.pending_migrations.length" class="text-info">{{ $t('plugins.pending') }}: <span class="font-mono">{{ selected.pending_migrations.join(', ') }}</span></p>
          </section>
        </div>
      </template>
    </UModal>

    <!-- After an update -->
    <UModal :open="!!syncAsk" :title="$t('plugins.syncAskTitle', { plugin: syncAsk?.label ?? '' })" :description="$t('plugins.syncAskText')" @update:open="(value: boolean) => { if (!value) syncAsk = null }">
      <template #footer>
        <div class="flex w-full flex-wrap justify-end gap-2">
          <UButton color="neutral" variant="ghost" :label="$t('plugins.syncSkip')" @click="syncAsk = null" />
          <UButton color="neutral" variant="outline" :loading="syncing" :label="$t('plugins.syncBlocks')" @click="sync(syncAsk!, false)" />
          <UButton :loading="syncing" icon="i-lucide-refresh-cw" :label="$t('plugins.syncTemplates')" @click="sync(syncAsk!, true)" />
        </div>
      </template>
    </UModal>
  </div>
</template>
