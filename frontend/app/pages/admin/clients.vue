<script setup lang="ts">
import type { ApiClient, Entity, Role } from '~/types/api'

definePageMeta({ admin: true })
const { t } = useI18n()
useHead({ title: () => t('nav.clients') })
const { relative } = useFormat()
const toast = useToast()

const { data, refresh } = await useAsyncData('admin-clients', () => useApi()<{ data: ApiClient[] }>('/admin/clients'))
const entities = (await useApi()<{ data: Entity[] }>('/admin/entities')).data
const roleItems = (await useApi()<{ data: Role[] }>('/admin/roles')).data.map(role => ({ value: role.slug, label: role.name }))
// Per entity: read, and optionally create/update/delete through the content API (writing implies reading)
type Access = { read: boolean, create: boolean, update: boolean, delete: boolean, update_own: boolean, delete_own: boolean }
const WRITES = computed(() => (['create', 'update', 'update_own', 'delete', 'delete_own'] as const).map(key => ({ key, label: t(`permissions.${key}`) })))
const none = (): Access => ({ read: false, create: false, update: false, delete: false, update_own: false, delete_own: false })

const open = ref(false)
const editing = ref<ApiClient | null>(null)
const form = reactive({ name: '', access: {} as Record<string, Access>, rate_limit: '', is_active: true, media: { upload: false, delete: false }, roles: [] as string[] })
function toggle(slug: string, key: keyof Access, value: boolean) {
  const access = form.access[slug]!
  access[key] = value
  if (value && key !== 'read') access.read = true
  if (!value && key === 'read') Object.assign(access, none())
}
const secret = ref<{ client_id: string, client_secret: string } | null>(null)
const { submit, saving, errors } = useSubmit()

function edit(client: ApiClient | null) {
  editing.value = client
  Object.assign(form, client
    ? { name: client.name, rate_limit: client.rate_limit === null ? '' : String(client.rate_limit), is_active: client.is_active, media: { ...client.media }, roles: [...(client.roles ?? [])] }
    : { name: '', rate_limit: '', is_active: true, media: { upload: false, delete: false }, roles: [] })
  form.access = Object.fromEntries(entities.map((e) => {
    const granted = client?.entities.find(c => c.slug === e.slug)
    return [e.slug, granted ? { read: true, create: granted.create, update: granted.update, delete: granted.delete, update_own: granted.update_own ?? false, delete_own: granted.delete_own ?? false } : none()]
  }))
  open.value = true
}

async function save() {
  const body = {
    name: form.name,
    is_active: form.is_active,
    rate_limit: form.rate_limit === '' ? null : Number(form.rate_limit),
    entities: Object.entries(form.access).filter(([, a]) => a.read).map(([entity, a]) => ({ entity, create: a.create, update: a.update, delete: a.delete, update_own: a.update_own, delete_own: a.delete_own })),
    media: form.media,
    roles: form.roles
  }
  const res = await submit(() => editing.value
    ? useApi()<{ data: ApiClient }>(`/admin/clients/${editing.value.id}`, { method: 'PUT', body })
    : useApi()<{ data: ApiClient }>('/admin/clients', { method: 'POST', body }), editing.value ? undefined : t('clients.created'))
  if (res) {
    open.value = false
    if (res.data.client_secret) secret.value = { client_id: res.data.client_id, client_secret: res.data.client_secret }
    await refresh()
  }
}

async function regenerate(client: ApiClient) {
  const res = await submit(() => useApi()<{ data: ApiClient }>(`/admin/clients/${client.id}/secret`, { method: 'POST' }), t('clients.secretRenewed'))
  if (res?.data.client_secret) secret.value = { client_id: res.data.client_id, client_secret: res.data.client_secret }
}

async function remove(client: ApiClient) {
  if (await submit(() => useApi()(`/admin/clients/${client.id}`, { method: 'DELETE' }), t('clients.deleted')) !== null) await refresh()
}

async function copy(text: string) {
  await navigator.clipboard.writeText(text)
  toast.add({ title: t('common.copied'), color: 'success', icon: 'i-lucide-clipboard-check' })
}

const curl = computed(() => secret.value ? `curl -X POST ${apiUrl('/oauth/token')} \\\n  -d grant_type=client_credentials \\\n  -d client_id=${secret.value.client_id} \\\n  -d client_secret=${secret.value.client_secret}` : '')
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <AppPageHeader :title="$t('nav.clients')" :subtitle="$t('clients.subtitle')">
      <template #actions>
        <UButton icon="i-lucide-plus" :label="$t('clients.new')" @click="edit(null)" />
      </template>
    </AppPageHeader>

    <UAlert v-if="secret" color="warning" variant="subtle" icon="i-lucide-key-round" :title="$t('clients.copySecret')" :close="true" @update:open="secret = null">
      <template #description>
        <div class="mt-2 space-y-2 font-mono text-xs">
          <div class="flex items-center gap-2"><span class="w-24 shrink-0">client_id</span><code class="truncate">{{ secret.client_id }}</code><UButton icon="i-lucide-copy" size="xs" variant="ghost" @click="copy(secret.client_id)" /></div>
          <div class="flex items-center gap-2"><span class="w-24 shrink-0">client_secret</span><code class="truncate">{{ secret.client_secret }}</code><UButton icon="i-lucide-copy" size="xs" variant="ghost" @click="copy(secret.client_secret)" /></div>
          <pre class="p-3 rounded bg-default overflow-x-auto">{{ curl }}</pre>
        </div>
      </template>
    </UAlert>

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <EmptyState v-if="!data?.data.length" icon="i-lucide-key-round" :text="$t('clients.empty')" />
      <ul v-else class="divide-y divide-default">
        <li v-for="client in data.data" :key="client.id" class="flex flex-wrap items-center gap-4 px-4 py-3 hover:bg-elevated/40">
          <UIcon name="i-lucide-key-round" class="size-5 text-muted shrink-0" />
          <button type="button" class="flex-1 min-w-48 text-start group" @click="edit(client)">
            <div class="font-medium flex items-center gap-2 group-hover:text-primary">
              {{ client.name }}
              <UBadge v-if="!client.is_active" :label="$t('users.inactive')" color="error" variant="subtle" />
            </div>
            <div class="text-sm text-muted font-mono truncate">{{ client.client_id }}</div>
            <div class="text-sm text-muted">
              {{ client.entities.map(e => e.create || e.update || e.delete ? $t('clients.writing', { entity: e.name }) : e.name).join(', ') || $t('clients.publicOnly') }}
              <template v-if="client.media.upload || client.media.delete">· {{ client.media.upload && client.media.delete ? $t('clients.mediaBoth') : client.media.upload ? $t('clients.mediaUpload') : $t('clients.mediaDelete') }}</template>
              · {{ client.rate_limit === null ? $t('clients.defaultLimit') : client.rate_limit === 0 ? $t('clients.unlimited') : $t('clients.limit', { count: client.rate_limit }) }}
              · {{ $t('clients.lastUsed', { time: relative(client.last_used_at) }) }}
            </div>
          </button>
          <ConfirmButton :label="$t('clients.newSecret')" icon="i-lucide-refresh-cw" color="warning" variant="ghost" :question="$t('clients.newSecretQuestion', { client: client.name })" @confirm="regenerate(client)" />
          <ConfirmButton :label="$t('common.delete')" icon="i-lucide-trash-2" variant="ghost" :question="$t('clients.deleteQuestion', { client: client.name })" @confirm="remove(client)" />
        </li>
      </ul>
    </UCard>

    <UModal v-model:open="open" :title="editing ? $t('clients.edit') : $t('clients.newLong')" :ui="{ content: 'sm:max-w-2xl' }">
      <template #body>
        <div class="space-y-4">
          <UFormField :label="$t('common.name')" :help="$t('clients.nameHelp')" :error="errors.name" required><UInput v-model="form.name" /></UFormField>
          <UFormField :label="$t('nav.roles')" :help="$t('clients.rolesHelp')" :error="errors.roles">
            <USelectMenu v-model="form.roles" :items="roleItems" value-key="value" multiple :placeholder="$t('users.noRoles')" class="w-full" />
          </UFormField>
          <UFormField :label="$t('clients.permissions')" :help="$t('clients.permissionsHelp')" :error="errors.entities">
            <div class="overflow-x-auto rounded-md border border-default">
              <table class="w-full text-sm">
                <thead class="bg-elevated/50">
                  <tr>
                    <th class="px-3 py-2 text-left">Entity</th>
                    <th class="px-2 py-2">{{ $t('permissions.read') }}</th>
                    <th v-for="w in WRITES" :key="w.key" class="px-2 py-2">{{ w.label }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-default">
                  <tr v-for="e in entities" :key="e.slug">
                    <td class="px-3 py-2">{{ e.name }} <span v-if="e.access !== 'oauth'" class="text-muted">({{ $t('clients.public') }})</span></td>
                    <td class="px-2 py-2"><UCheckbox :id="`client-${e.slug}-read`" :model-value="form.access[e.slug]!.read" class="justify-center" :aria-label="`${e.name}: ${$t('permissions.read')}`" @update:model-value="toggle(e.slug, 'read', !!$event)" /></td>
                    <td v-for="w in WRITES" :key="w.key" class="px-2 py-2"><UCheckbox :id="`client-${e.slug}-${w.key}`" :model-value="form.access[e.slug]![w.key]" class="justify-center" :aria-label="`${e.name}: ${w.label}`" @update:model-value="toggle(e.slug, w.key, !!$event)" /></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </UFormField>
          <UFormField :label="$t('nav.media')" :help="$t('clients.mediaHelp')">
            <div class="flex flex-wrap gap-x-6 gap-y-2">
              <UCheckbox id="client-media-upload" v-model="form.media.upload" :label="$t('clients.upload')" />
              <UCheckbox id="client-media-delete" v-model="form.media.delete" :label="$t('common.delete')" />
            </div>
          </UFormField>
          <UFormField :label="$t('nav.rateLimit')" :help="$t('clients.rateLimitHelp')" :error="errors.rate_limit">
            <UInput v-model="form.rate_limit" type="number" min="0" />
          </UFormField>
          <USwitch v-if="editing" id="client-active" v-model="form.is_active" :label="$t('common.active')" />
        </div>
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
