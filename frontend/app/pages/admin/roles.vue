<script setup lang="ts">
import type { Entity, Permissions, Role } from '~/types/api'

// Roles (yiisoft/rbac): permissions per entity, contained roles (their permissions count too),
// media and taking over records. Users and API clients get them; fields can be limited to them.
definePageMeta({ admin: true })
const { t } = useI18n()
useHead({ title: () => t('nav.roles') })
const { project } = useAuth()

const { data, refresh } = await useAsyncData('admin-roles-page', () => useApi()<{ data: Role[] }>('/admin/roles'))
const roles = computed(() => data.value?.data ?? [])
const entities = (await useApi()<{ data: Entity[] }>('/admin/entities')).data
const { submit, saving, errors } = useSubmit()

const empty = (): Permissions => ({ read: false, create: false, update: false, delete: false, import: false, update_own: false, delete_own: false })
const open = ref(false)
const editing = ref<Role | null>(null)
const form = reactive({ slug: '', name: '', roles: [] as string[], permissions: {} as Record<string, Permissions>, media_upload: false, media_delete: false, take_over: false })

function edit(role: Role | null) {
  editing.value = role
  Object.assign(form, {
    slug: role?.slug ?? '',
    name: role?.name ?? '',
    roles: [...(role?.roles ?? [])],
    permissions: Object.fromEntries(entities.map(e => [e.id, { ...empty(), ...(role?.permissions[e.id] ?? {}) }])),
    media_upload: role?.media_upload ?? false,
    media_delete: role?.media_delete ?? false,
    take_over: role?.take_over ?? false
  })
  open.value = true
}
// The slug follows the name of a new role
watch(() => form.name, (name, previous) => {
  if (!editing.value && (!form.slug || form.slug === slugify(previous ?? '', 60).replaceAll('-', '_'))) form.slug = slugify(name, 60).replaceAll('-', '_')
})

const containable = computed(() => roles.value.filter(role => role.slug !== editing.value?.slug).map(role => ({ value: role.slug, label: role.name })))
const inherited = computed(() => rolePermissions(roles.value, form.roles))

async function save() {
  const body = { ...form }
  const saved = await submit(() => editing.value
    ? useApi()<{ data: Role }>(`/admin/roles/${editing.value.slug}`, { method: 'PUT', body })
    : useApi()<{ data: Role }>('/admin/roles', { method: 'POST', body }), editing.value ? undefined : t('roles.created'))
  if (saved) {
    open.value = false
    await refresh()
  }
}
async function remove(role: Role) {
  if (await submit(() => useApi()(`/admin/roles/${role.slug}`, { method: 'DELETE' }), t('roles.deleted')) !== null) {
    open.value = false
    await refresh()
  }
}
const permissionCount = (role: Role) => Object.values(role.permissions).filter(p => Object.values(p).some(Boolean)).length
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <AppPageHeader :title="$t('nav.roles')" :subtitle="$t('roles.subtitle')">
      <template #actions>
        <UButton icon="i-lucide-plus" :label="$t('roles.new')" @click="edit(null)" />
      </template>
    </AppPageHeader>

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <ul v-if="roles.length" class="divide-y divide-default">
        <li v-for="role in roles" :key="role.slug">
          <button type="button" class="group flex w-full flex-wrap items-center gap-4 px-4 py-3 text-left hover:bg-elevated/40" @click="edit(role)">
            <UIcon name="i-lucide-shield" class="size-5 text-muted shrink-0" />
            <div class="min-w-0 flex-1">
              <div class="font-medium group-hover:text-primary">{{ role.name }} <span class="font-mono text-xs font-normal text-muted">{{ role.slug }}</span></div>
              <div class="text-sm text-muted">
                {{ $t('roles.summary', { entities: permissionCount(role), users: role.users, clients: role.clients }) }}
                <template v-if="role.roles.length"> · {{ $t('roles.contains', { roles: role.roles.map(slug => roles.find(r => r.slug === slug)?.name ?? slug).join(', ') }) }}</template>
              </div>
            </div>
            <UBadge v-if="role.take_over" :label="$t('roles.takeOver')" color="info" variant="subtle" size="sm" />
            <UBadge v-if="role.media_upload || role.media_delete" :label="$t('nav.media')" color="neutral" variant="subtle" size="sm" />
          </button>
        </li>
      </ul>
      <EmptyState v-else :text="$t('roles.none')" />
    </UCard>

    <UModal v-model:open="open" :title="editing ? editing.name : $t('roles.new')" :ui="{ content: 'sm:max-w-3xl' }">
      <template #body>
        <form class="space-y-5" @submit.prevent="save">
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField :label="$t('common.name')" :error="errors.name" required><UInput v-model="form.name" class="w-full" /></UFormField>
            <UFormField :label="$t('common.technicalName')" :help="editing ? undefined : $t('roles.slugHelp')" :error="errors.slug">
              <UInput v-model="form.slug" class="w-full font-mono" :disabled="!!editing" />
            </UFormField>
          </div>
          <UFormField :label="$t('roles.containsLabel')" :help="$t('roles.containsHelp')" :error="errors.roles">
            <USelectMenu v-model="form.roles" :items="containable" value-key="value" multiple :placeholder="$t('users.noRoles')" class="w-full" />
          </UFormField>
          <div>
            <p class="mb-2 text-sm font-medium">{{ $t('users.permissions') }} <span v-if="project" class="font-normal text-muted">{{ $t('users.inProject', { project: project.name }) }}</span></p>
            <div class="rounded-md border border-default">
              <PermissionMatrix v-model="form.permissions" :entities="entities" :inherited="inherited" />
            </div>
          </div>
          <div class="flex flex-wrap gap-x-6 gap-y-2">
            <UCheckbox id="role-take-over" v-model="form.take_over" :label="$t('roles.takeOver')" :description="$t('roles.takeOverHelp')" />
            <UCheckbox id="role-media-upload" v-model="form.media_upload" :label="$t('roles.mediaUpload')" :description="$t('roles.mediaHelp')" />
            <UCheckbox id="role-media-delete" v-model="form.media_delete" :label="$t('roles.mediaDelete')" />
          </div>
        </form>
      </template>
      <template #footer>
        <div class="flex w-full flex-wrap justify-between gap-3">
          <ConfirmButton v-if="editing" :label="$t('common.delete')" icon="i-lucide-trash-2" variant="ghost" :question="$t('roles.deleteQuestion', { name: editing.name, users: editing.users, clients: editing.clients })" @confirm="remove(editing)" />
          <span v-else />
          <div class="flex gap-3">
            <UButton color="neutral" variant="outline" :label="$t('common.cancel')" @click="open = false" />
            <UButton :icon="editing ? 'i-lucide-save' : 'i-lucide-plus'" :loading="saving" :label="editing ? $t('common.save') : $t('common.create')" @click="save" />
          </div>
        </div>
      </template>
    </UModal>
  </div>
</template>
