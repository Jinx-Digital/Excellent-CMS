<script setup lang="ts">
import type { Entity, Permissions, Project, Role, User } from '~/types/api'

definePageMeta({ admin: true })
const route = useRoute()
const id = route.params.id as string
const isNew = id === 'new'
const { session, project } = useAuth()
const { t } = useI18n()
useHead({ title: () => isNew ? t('users.new') : t('users.edit') })

const empty = (): Permissions => ({ read: false, create: false, update: false, delete: false, import: false, update_own: false, delete_own: false })

const entities = (await useApi()<{ data: Entity[] }>('/admin/entities')).data
const user = isNew ? null : (await useApi()<{ data: User }>(`/admin/users/${id}`)).data
const allProjects = (await useApi()<{ data: Project[] }>('/admin/projects')).data
const roles = (await useApi()<{ data: Role[] }>('/admin/roles')).data
const form = reactive({
  name: user?.name ?? '',
  email: user?.email ?? '',
  password: '',
  is_admin: user?.is_admin ?? false,
  roles: [...(user?.roles ?? [])],
  is_active: user?.is_active ?? true,
  // New users start in the current project
  projects: user?.projects ?? (project.value ? [project.value.id] : []),
  permissions: Object.fromEntries(entities.map(e => [e.id, { ...empty(), ...(user?.permissions[e.id] ?? {}) }])) as Record<string, Permissions>
})
const isSelf = computed(() => session.value?.user.id === id)
// Administrators can do everything; everyone else gets roles and permissions of their own
const isAdminRole = computed(() => form.is_admin)
const roleItems = roles.map(role => ({ value: role.slug, label: role.name }))
const inherited = computed(() => rolePermissions(roles, form.roles))
const { submit, saving, errors } = useSubmit()

async function save() {
  const body = { ...form, password: form.password || undefined }
  const ok = await submit(() => isNew ? useApi()('/admin/users', { method: 'POST', body }) : useApi()(`/admin/users/${id}`, { method: 'PUT', body }), isNew ? t('users.created') : undefined)
  if (ok !== null) await navigateTo('/admin/users')
}
async function remove() {
  if (await submit(() => useApi()(`/admin/users/${id}`, { method: 'DELETE' }), t('users.deleted')) !== null) await navigateTo('/admin/users')
}
</script>

<template>
  <form class="max-w-5xl mx-auto space-y-6" @submit.prevent="save">
    <AppPageHeader :title="isNew ? $t('users.new') : form.name" back="/admin/users">
      <template #actions>
        <ConfirmButton v-if="!isNew && !isSelf" :label="$t('common.delete')" icon="i-lucide-trash-2" variant="ghost" :question="$t('users.deleteQuestion', { name: form.name })" @confirm="remove" />
      </template>
    </AppPageHeader>

    <UCard>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField :label="$t('common.name')" :error="errors.name" required><UInput v-model="form.name" /></UFormField>
        <UFormField :label="$t('users.emailLogin')" :error="errors.email" required><UInput v-model="form.email" type="email" /></UFormField>
        <UFormField :label="isNew ? $t('auth.password') : $t('auth.newPassword')" :help="isNew ? $t('auth.passwordHint') : $t('users.keepPassword')" :error="errors.password" :required="isNew">
          <UInput v-model="form.password" type="password" autocomplete="new-password" />
        </UFormField>
        <div class="flex flex-col gap-1 md:col-span-2">
          <USwitch id="user-admin" v-model="form.is_admin" :label="$t('users.admin')" :description="$t('users.adminHelp')" :disabled="isSelf" />
          <p v-if="errors.is_admin" class="text-sm text-error">{{ errors.is_admin }}</p>
        </div>
        <UFormField v-if="!form.is_admin" :label="$t('nav.roles')" :help="$t('users.rolesHelp')" :error="errors.roles" class="md:col-span-2">
          <USelectMenu v-model="form.roles" :items="roleItems" value-key="value" multiple :placeholder="$t('users.noRoles')" class="w-full" />
        </UFormField>
        <div v-if="!isNew" class="flex flex-col gap-3">
          <USwitch id="user-active" v-model="form.is_active" :label="$t('common.active')" :disabled="isSelf" />
          <p v-if="errors.is_active" class="text-sm text-error">{{ errors.is_active }}</p>
        </div>
      </div>
    </UCard>

    <UCard v-if="!isAdminRole">
      <template #header>
        <h2 class="font-semibold">{{ $t('nav.projects') }}</h2>
        <p class="text-sm text-muted">{{ $t('users.projectsHelp') }}</p>
      </template>
      <div class="flex flex-wrap gap-x-6 gap-y-2">
        <UCheckbox
          v-for="p in allProjects"
          :id="`user-project-${p.id}`"
          :key="p.id"
          :model-value="form.projects.includes(p.id)"
          :label="p.name"
          @update:model-value="form.projects = $event ? [...form.projects, p.id] : form.projects.filter(other => other !== p.id)"
        />
      </div>
      <p v-if="errors.projects" class="mt-2 text-sm text-error">{{ errors.projects }}</p>
    </UCard>

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <template #header>
        <h2 class="font-semibold">{{ $t('users.permissions') }} <span v-if="project" class="text-muted font-normal">{{ $t('users.inProject', { project: project.name }) }}</span></h2>
        <p class="text-sm text-muted">{{ isAdminRole ? $t('users.adminsHaveAll') : $t('users.writeImpliesRead') }}</p>
      </template>
      <PermissionMatrix v-model="form.permissions" :entities="entities" :inherited="inherited" :disabled="isAdminRole" />
      <p v-if="errors.permissions" class="px-4 pb-4 text-sm text-error">{{ errors.permissions }}</p>
    </UCard>

    <div class="flex justify-end"><UButton :icon="isNew ? 'i-lucide-plus' : 'i-lucide-save'" type="submit" :loading="saving" :label="isNew ? $t('common.create') : $t('common.save')" /></div>
  </form>
</template>
