<script setup lang="ts">
import type { Storage, StorageField, StorageType } from '~/types/api'

// Storages for uploads - for all projects, each project picks one (default: the built-in "local",
// listed but not editable). Every setting takes a value or a $NAME .env variable; secrets
// entered as values are stored encrypted and never come back - empty keeps them.
definePageMeta({ admin: true })
const { t, te } = useI18n()
useHead({ title: () => t('nav.storages') })
const { number } = useFormat()

const { data, refresh } = await useAsyncData('admin-storages', () => useApi()<{ data: Storage[] }>('/admin/storages'))
const storages = computed(() => data.value?.data ?? [])
const { data: typeData } = await useAsyncData('admin-storage-types', () => useApi()<{ data: { types: StorageType[], encryption: boolean } }>('/admin/storages/types'))
const types = computed(() => typeData.value?.data.types ?? [])
const encryption = computed(() => typeData.value?.data.encryption ?? false)
const typeOf = (type: string) => types.value.find(entry => entry.type === type)
const typeItems = computed(() => [
  { type: 'label' as const, label: t('storages.groupServer') },
  ...types.value.filter(entry => entry.group === 'server').map(entry => ({ value: entry.type, label: entry.label })),
  { type: 'label' as const, label: t('storages.groupS3') },
  ...types.value.filter(entry => entry.group === 's3').map(entry => ({ value: entry.type, label: entry.label })),
])

const { submit, saving, errors } = useSubmit()
const open = ref(false)
const editing = ref<Storage | null>(null)
const form = reactive({ name: '', label: '', type: 'local', private: true })
const settings = reactive<Record<string, string | boolean>>({})
const secrets = reactive<Record<string, string>>({})
const fields = computed<StorageField[]>(() => (typeOf(form.type)?.fields ?? []).filter(field => field.key !== 'url' || !form.private))

// The settings of the type: the saved ones of the storage, otherwise empty (bools: their default)
function initSettings() {
  for (const key of Object.keys(settings)) delete settings[key]
  for (const key of Object.keys(secrets)) delete secrets[key]
  const same = editing.value?.type === form.type
  for (const field of typeOf(form.type)?.fields ?? []) {
    if (field.kind === 'bool') settings[field.key] = same ? !!editing.value?.settings?.[field.key] : !!field.default
    else if (field.kind === 'text') settings[field.key] = same ? String(editing.value?.settings?.[field.key] ?? '') : ''
    else secrets[field.key] = same && editing.value?.secrets?.[field.key]?.env ? `$${editing.value.secrets[field.key]!.env}` : ''
  }
}
watch(() => form.type, initSettings)

function edit(storage: Storage | null) {
  editing.value = storage
  Object.assign(form, storage
    ? { name: storage.name, label: storage.own_label ?? '', type: storage.type, private: storage.private }
    : { name: '', label: '', type: 'local', private: true })
  initSettings()
  errors.value = {}
  open.value = true
}

const stored = (key: string) => editing.value?.type === form.type && !!editing.value?.secrets?.[key]?.stored
const label = (key: string) => te(`storages.field.${key}`) ? t(`storages.field.${key}`) : key
const help = (key: string) => te(`storages.help.${key}`) ? t(`storages.help.${key}`) : undefined
const secretHelp = (key: string) => {
  if (stored(key)) return t('storages.secretStored')
  return encryption.value ? t('storages.secretHelp') : t('storages.secretEnvOnly')
}

async function save() {
  // Secrets: empty keeps a stored value, otherwise the secret is removed
  const secretBody = Object.fromEntries(Object.entries(secrets).map(([key, value]) => [key, value.trim() || (stored(key) ? '' : null)]))
  const body = { ...form, settings: { ...settings }, secrets: secretBody }
  const res = await submit(() => editing.value?.id
    ? useApi()<{ data: Storage }>(`/admin/storages/${editing.value.id}`, { method: 'PUT', body })
    : useApi()<{ data: Storage }>('/admin/storages', { method: 'POST', body }), editing.value ? t('storages.saved') : t('storages.created'))
  if (res) {
    open.value = false
    await refresh()
    if (!editing.value) await test(res.data)
  }
}

const testing = ref<string | null>(null)
const results = ref<Record<string, { ok: boolean, error: string | null }>>({})
async function test(storage: Storage) {
  if (!storage.id) return
  testing.value = storage.id
  const res = await submit(() => useApi()<{ data: { ok: boolean, error: string | null } }>(`/admin/storages/${storage.id}/test`, { method: 'POST' }), '')
  testing.value = null
  if (res) results.value[storage.id] = res.data
}

async function remove(storage: Storage) {
  if (await submit(() => useApi()(`/admin/storages/${storage.id}`, { method: 'DELETE' }), t('storages.deleted')) !== null) await refresh()
}

const icon = (storage: Storage) => storage.type === 'local' ? 'i-lucide-hard-drive' : typeOf(storage.type)?.group === 's3' ? 'i-lucide-cloud' : 'i-lucide-server'
const inUse = (storage: Storage) => (storage.usage?.media ?? 0) + (storage.usage?.projects ?? 0) > 0
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <AppPageHeader :title="$t('nav.storages')" :subtitle="$t('storages.subtitle')">
      <template #actions>
        <UButton icon="i-lucide-plus" :label="$t('storages.new')" @click="edit(null)" />
      </template>
    </AppPageHeader>

    <UAlert v-if="!encryption" color="warning" variant="subtle" icon="i-lucide-key-round" :title="$t('storages.noKeyTitle')" :description="$t('storages.noKeyText')" />

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <ul class="divide-y divide-default">
        <li v-for="storage in storages" :key="storage.name" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3" :class="storage.editable && 'hover:bg-elevated/40'">
          <UIcon :name="icon(storage)" class="size-5 text-muted shrink-0" />
          <component :is="storage.editable ? 'button' : 'div'" type="button" class="flex-1 min-w-0 text-start group" @click="storage.editable && edit(storage)">
            <div class="font-medium truncate" :class="storage.editable && 'group-hover:text-primary'">
              {{ storage.label }}
              <span class="font-mono text-xs text-muted">{{ storage.name }}</span>
              <UBadge v-if="storage.default" :label="$t('storages.default')" color="primary" variant="subtle" size="sm" class="ms-1" />
              <UBadge v-if="!storage.editable" :label="$t('storages.builtIn')" color="neutral" variant="subtle" size="sm" class="ms-1" />
            </div>
            <div class="text-sm text-muted truncate">
              {{ typeOf(storage.type)?.label ?? storage.type }} · {{ storage.private ? $t('projects.storagePrivate') : $t('projects.storagePublic') }}
              <template v-if="storage.usage"> · {{ $t('storages.usage', { media: number(storage.usage.media), projects: number(storage.usage.projects) }) }}</template>
            </div>
            <div v-if="storage.id && results[storage.id]" class="text-xs mt-1" :class="results[storage.id]!.ok ? 'text-success' : 'text-error'">
              {{ results[storage.id]!.ok ? $t('storages.testOk') : $t('storages.testFailed', { error: results[storage.id]!.error }) }}
            </div>
          </component>
          <template v-if="storage.editable">
            <UButton size="sm" color="neutral" variant="outline" icon="i-lucide-plug-zap" :loading="testing === storage.id" :label="$t('storages.test')" @click="test(storage)" />
            <ConfirmButton
              v-if="!inUse(storage)"
              :label="$t('common.delete')"
              icon="i-lucide-trash-2"
              variant="ghost"
              size="sm"
              :question="$t('storages.deleteQuestion', { storage: storage.label })"
              @confirm="remove(storage)"
            />
          </template>
        </li>
      </ul>
    </UCard>

    <UModal v-model:open="open" :title="editing ? $t('storages.titleOf', { storage: editing.label }) : $t('storages.new')" :ui="{ content: 'sm:max-w-xl' }">
      <template #body>
        <form class="space-y-4" @submit.prevent="save">
          <div class="grid sm:grid-cols-2 gap-4">
            <UFormField :label="$t('common.technicalName')" :help="editing ? $t('storages.nameLocked') : $t('storages.nameHelp')" :error="errors.name" required>
              <UInput v-model="form.name" class="font-mono w-full" :disabled="!!editing" placeholder="kunde_a" />
            </UFormField>
            <UFormField :label="$t('common.name')" :error="errors.label">
              <UInput v-model="form.label" class="w-full" :placeholder="form.name" />
            </UFormField>
          </div>
          <UFormField :label="$t('storages.type')" :error="errors.type" required>
            <USelect v-model="form.type" :items="typeItems" class="w-full" />
          </UFormField>
          <UFormField v-if="form.type !== 'local'" :help="form.private ? $t('storages.privateHelp') : $t('storages.publicHelp')">
            <USwitch v-model="form.private" :label="$t('storages.private')" />
          </UFormField>

          <div class="grid sm:grid-cols-2 gap-4">
            <template v-for="field in fields" :key="`${form.type}-${field.key}`">
              <UFormField v-if="field.kind === 'bool'" class="sm:col-span-2" :help="help(field.key)">
                <UCheckbox :model-value="!!settings[field.key]" :label="label(field.key)" @update:model-value="settings[field.key] = !!$event" />
              </UFormField>
              <UFormField
                v-else-if="field.kind === 'text'"
                :class="['url', 'endpoint', 'base_uri', 'path', 'root'].includes(field.key) && 'sm:col-span-2'"
                :label="label(field.key)"
                :help="help(field.key)"
                :error="errors[`settings.${field.key}`]"
                :required="field.required || (field.key === 'url' && !form.private && ['ftp', 'sftp', 'webdav', 'r2'].includes(form.type))"
              >
                <EnvInput :model-value="String(settings[field.key] ?? '')" :placeholder="field.placeholder" @update:model-value="settings[field.key] = $event" />
              </UFormField>
              <UFormField v-else-if="field.kind === 'key'" class="sm:col-span-2" :label="label(field.key)" :help="secretHelp(field.key)" :error="errors[`secrets.${field.key}`]">
                <UTextarea v-model="secrets[field.key]" :rows="3" autoresize class="w-full font-mono text-xs" :placeholder="stored(field.key) ? '••••••••' : '-----BEGIN OPENSSH PRIVATE KEY----- … / $SFTP_KEY'" />
              </UFormField>
              <UFormField v-else :label="label(field.key)" :help="secretHelp(field.key)" :error="errors[`secrets.${field.key}`]" :required="field.required && !stored(field.key)">
                <EnvInput v-model="secrets[field.key]" secret :placeholder="stored(field.key) ? '••••••••' : undefined" />
              </UFormField>
            </template>
          </div>
        </form>
      </template>
      <template #footer>
        <div class="flex justify-end gap-3 w-full">
          <UButton color="neutral" variant="outline" :label="$t('common.cancel')" @click="open = false" />
          <UButton :icon="editing ? 'i-lucide-save' : 'i-lucide-plus'" :loading="saving" :label="editing ? $t('common.save') : $t('storages.createAndTest')" @click="save" />
        </div>
      </template>
    </UModal>
  </div>
</template>
