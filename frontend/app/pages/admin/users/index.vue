<script setup lang="ts">
import type { Paged, Role, User } from '~/types/api'

definePageMeta({ admin: true })
const { t } = useI18n()
useHead({ title: () => t('nav.users') })
const { relative } = useFormat()

const search = ref('')
const page = ref(1)
const query = computed(() => ({ s: search.value || undefined, page: page.value, limit: 50 }))
const { data } = await useAsyncData('admin-users', () => useApi()<Paged<User>>('/admin/users', { query: query.value }), { watch: [query] })
const { data: roleData } = await useAsyncData('admin-roles', () => useApi()<{ data: Role[] }>('/admin/roles'))
const roleNames = computed<Record<string, string>>(() => Object.fromEntries((roleData.value?.data ?? []).map(role => [role.slug, role.name])))
watchDebounced(search, () => { page.value = 1 }, { debounce: 300 })
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <AppPageHeader :title="$t('nav.users')" :subtitle="$t('users.subtitle')">
      <template #actions>
        <UButton to="/admin/users/new" icon="i-lucide-user-plus" :label="$t('users.new')" />
      </template>
    </AppPageHeader>

    <UInput v-model="search" icon="i-lucide-search" :placeholder="$t('users.search')" class="max-w-sm mb-4" />

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <ul class="divide-y divide-default">
        <li v-for="user in data?.data ?? []" :key="user.id">
          <NuxtLink :to="`/admin/users/${user.id}`" class="group flex items-center gap-4 px-4 py-3 hover:bg-elevated/40">
            <UAvatar :alt="user.name" />
            <div class="flex-1 min-w-0">
              <div class="font-medium truncate group-hover:text-primary">{{ user.name }}</div>
              <div class="text-sm text-muted truncate">{{ user.email }}</div>
            </div>
            <div class="hidden sm:block text-sm text-muted">{{ $t('users.lastSeen', { time: relative(user.last_login_at) }) }}</div>
            <UBadge v-if="user.is_admin" :label="$t('users.roles.admin')" color="primary" variant="subtle" />
            <template v-else>
              <UBadge v-for="slug in user.roles" :key="slug" :label="roleNames[slug] ?? slug" color="info" variant="subtle" />
              <UBadge :label="$t('users.entities', Object.keys(user.permissions).length)" color="neutral" variant="subtle" />
            </template>
            <UBadge v-if="!user.is_active" :label="$t('users.inactive')" color="error" variant="subtle" />
          </NuxtLink>
        </li>
      </ul>
    </UCard>
    <div v-if="(data?.meta.total_pages ?? 0) > 1" class="mt-4 flex justify-center">
      <UPagination v-model:page="page" :total="data?.meta.total_items ?? 0" :items-per-page="50" />
    </div>
  </div>
</template>
