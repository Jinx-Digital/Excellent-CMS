<script setup lang="ts">
const previewOpen = usePreviewOpen()
const route = useRoute()
const { session, readable, isAdmin, canImport, logout, projects, project, switchProject } = useAuth()
const projectItems = computed(() => projects.value.map(p => ({ value: p.slug, label: p.name, icon: p.is_global ? 'i-lucide-globe' : undefined })))
// Entities of the area "Global" come in their own section (in the area itself they are the content)
const ownEntities = computed(() => readable.value.filter(e => !e.global || project.value?.is_global))
const globalEntities = computed(() => project.value?.is_global ? [] : readable.value.filter(e => e.global))

const isActive = (to: string) => to === '/' ? route.path === '/' : route.path === to || route.path.startsWith(to + '/')

const { t } = useI18n()
const adminItems = computed(() => [
  { to: '/admin/projects', icon: 'i-lucide-folder-kanban', label: t('nav.projects') },
  { to: '/admin/schema', icon: 'i-lucide-blocks', label: t('nav.schema') },
  { to: '/admin/blocks', icon: 'i-lucide-boxes', label: t('nav.blocks') },
  { to: '/admin/groups', icon: 'i-lucide-layers', label: t('nav.groups') },
  { to: '/admin/variables', icon: 'i-lucide-braces', label: t('nav.variables') },
  { to: '/admin/users', icon: 'i-lucide-users', label: t('nav.users') },
  { to: '/admin/roles', icon: 'i-lucide-shield', label: t('nav.roles') },
  { to: '/admin/search', icon: 'i-lucide-text-search', label: t('nav.search') },
  { to: '/admin/storages', icon: 'i-lucide-hard-drive', label: t('nav.storages') },
  { to: '/admin/plugins', icon: 'i-lucide-puzzle', label: t('nav.plugins') },
  { to: '/admin/clients', icon: 'i-lucide-key-round', label: t('nav.clients') },
  { to: '/admin/events', icon: 'i-lucide-zap', label: t('nav.events') },
  { to: '/admin/settings', icon: 'i-lucide-gauge', label: t('nav.rateLimit') }
])

// Pages of active plugins (e.g. forms) - refreshed when plugins change
const { data: pluginPagesData } = usePluginPages()
const pluginPages = computed(() => pluginPagesData.value ?? [])

const mobileOpen = ref(false)
const searchOpen = useState('global-search-open', () => false)
watch(() => route.fullPath, () => { mobileOpen.value = false })
</script>

<template>
  <div class="min-h-svh lg:flex">
    <aside
      class="fixed inset-y-0 start-0 z-40 w-72 shrink-0 flex flex-col border-e border-default bg-default transition-transform lg:sticky lg:top-0 lg:h-svh lg:translate-x-0"
      :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="h-16 px-5 flex items-center justify-between gap-2 border-b border-default">
        <NuxtLink to="/"><AppLogo /></NuxtLink>
        <LocaleSwitch class="hidden lg:flex" />
      </div>

      <div v-if="projects.length" class="px-3 pt-3">
        <USelect
          :model-value="project?.slug"
          :items="projectItems"
          icon="i-lucide-folder-kanban"
          class="w-full"
          :aria-label="$t('nav.project')"
          :disabled="projects.length < 2"
          @update:model-value="switchProject($event as string)"
        />
      </div>

      <div class="px-3 pt-3">
        <button type="button" class="flex w-full items-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-sm text-muted transition hover:border-accented hover:text-default" @click="searchOpen = true">
          <UIcon name="i-lucide-search" class="size-4" />
          <span class="flex-1 text-left">{{ $t('search.button') }}</span>
          <UKbd value="meta" size="sm" /><UKbd value="K" size="sm" />
        </button>
      </div>

      <nav class="flex-1 overflow-y-auto p-3 space-y-1">
        <NavLink to="/" icon="i-lucide-layout-dashboard" :label="$t('nav.overview')" :active="isActive('/')" />
        <NavLink v-if="canImport" to="/import" icon="i-lucide-file-up" :label="$t('nav.import')" :active="isActive('/import')" />
        <NavLink to="/library" icon="i-lucide-images" :label="$t('nav.media')" :active="isActive('/library')" />
        <NavLink to="/docs" icon="i-lucide-code-xml" :label="$t('nav.docs')" :active="isActive('/docs')" />

        <div class="pt-4 pb-1 px-3 text-xs font-semibold uppercase tracking-wide text-muted">{{ $t('nav.content') }}</div>
        <NavLink
          v-for="entity in ownEntities"
          :key="entity.slug"
          :to="`/entities/${entity.slug}`"
          icon="i-lucide-table-2"
          :label="entity.name"
          :active="isActive(`/entities/${entity.slug}`)"
        />
        <p v-if="!ownEntities.length" class="px-3 py-2 text-sm text-muted">{{ $t('nav.noContent') }}</p>

        <template v-if="globalEntities.length">
          <div class="pt-4 pb-1 px-3 text-xs font-semibold uppercase tracking-wide text-muted">{{ $t('nav.global') }}</div>
          <NavLink
            v-for="entity in globalEntities"
            :key="entity.slug"
            :to="`/entities/${entity.slug}`"
            icon="i-lucide-globe"
            :label="entity.name"
            :active="isActive(`/entities/${entity.slug}`)"
          />
        </template>

        <template v-if="pluginPages.length">
          <div class="pt-4 pb-1 px-3 text-xs font-semibold uppercase tracking-wide text-muted">{{ $t('nav.pluginPages') }}</div>
          <NavLink
            v-for="page in pluginPages"
            :key="`${page.plugin}/${page.key}`"
            :to="`/plugins/${page.plugin}/${page.key}`"
            :icon="page.icon"
            :label="page.label"
            :active="isActive(`/plugins/${page.plugin}/${page.key}`)"
          />
        </template>

        <template v-if="isAdmin">
          <div class="pt-4 pb-1 px-3 text-xs font-semibold uppercase tracking-wide text-muted">{{ $t('nav.administration') }}</div>
          <NavLink v-for="item in adminItems" :key="item.to" v-bind="item" :active="isActive(item.to)" />
        </template>
      </nav>

      <div class="p-3 border-t border-default flex items-center gap-2">
        <NuxtLink to="/account" class="flex-1 min-w-0 flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-elevated">
          <UAvatar :alt="session?.user.name" size="sm" />
          <span class="min-w-0">
            <span class="block text-sm font-medium truncate">{{ session?.user.name }}</span>
            <span class="block text-xs text-muted truncate">{{ session?.user.email }}</span>
          </span>
        </NuxtLink>
        <UButton icon="i-lucide-log-out" color="neutral" variant="ghost" :aria-label="$t('nav.signOut')" @click="logout" />
      </div>
    </aside>
    <div v-if="mobileOpen" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="mobileOpen = false" />

    <div class="flex-1 min-w-0 flex flex-col">
      <header class="lg:hidden sticky top-0 z-20 h-14 flex items-center gap-3 px-4 bg-default/95 backdrop-blur border-b border-default">
        <UButton icon="i-lucide-menu" color="neutral" variant="ghost" :aria-label="$t('nav.menu')" @click="mobileOpen = true" />
        <AppLogo />
        <div class="ms-auto flex items-center gap-1">
          <UButton icon="i-lucide-search" color="neutral" variant="ghost" :aria-label="$t('search.button')" @click="searchOpen = true" />
          <LocaleSwitch />
        </div>
      </header>
      <!-- With the preview of a record open (PreviewPanel, fixed on the right): the content uses the rest of the width -->
      <main class="flex-1 w-full p-4 sm:p-6 lg:p-8" :class="previewOpen ? 'xl:pe-[calc(min(50vw,60rem)+2rem)]' : 'max-w-7xl mx-auto'">
        <slot />
      </main>
    </div>
    <GlobalSearch />
  </div>
</template>
