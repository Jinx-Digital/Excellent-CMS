<script setup lang="ts">
import type { Entity } from '~/types/api'

const { t } = useI18n()
useHead({ title: () => t('nav.overview') })
const { session, isAdmin, canImport } = useAuth()
const { number } = useFormat()

const { data, pending } = await useAsyncData('entities', () => useApi()<{ data: Entity[] }>('/entities'))
const entities = computed(() => (data.value?.data ?? []).filter(e => e.permissions?.read))
</script>

<template>
  <div>
    <AppPageHeader :title="$t('home.hello', { name: session?.user.name ?? '' })" :subtitle="$t('home.subtitle')">
      <template #actions>
        <UButton v-if="canImport" to="/import" icon="i-lucide-file-up" :label="$t('home.import')" />
      </template>
    </AppPageHeader>

    <div v-if="pending" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <USkeleton v-for="i in 3" :key="i" class="h-32" />
    </div>

    <UCard v-else-if="!entities.length" :ui="{ body: 'p-0 sm:p-0' }">
      <EmptyState icon="i-lucide-sheet" :text="isAdmin ? $t('home.emptyAdmin') : $t('home.emptyEditor')">
        <UButton v-if="isAdmin" to="/import" icon="i-lucide-file-up" :label="$t('home.importFirst')" />
      </EmptyState>
    </UCard>

    <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <NuxtLink v-for="entity in entities" :key="entity.id" :to="`/entities/${entity.slug}`" class="group">
        <UCard class="h-full group-hover:ring-primary/50 transition">
          <div class="flex items-start gap-3">
            <div class="size-10 rounded-lg bg-primary/10 text-primary grid place-items-center shrink-0">
              <UIcon name="i-lucide-table-2" class="size-5" />
            </div>
            <div class="flex-1 min-w-0">
              <h2 class="font-semibold truncate">{{ entity.name }}</h2>
              <p class="text-sm text-muted font-mono truncate">/content/{{ entity.slug }}</p>
            </div>
            <AccessBadge :access="entity.access" />
          </div>
          <div class="mt-4 flex items-end justify-between">
            <div>
              <div class="text-3xl font-bold">{{ number(entity.record_count ?? 0) }}</div>
              <div class="text-sm text-muted">{{ $t('home.recordsFields', { fields: entity.fields.length }, entity.record_count ?? 0) }}</div>
            </div>
            <UIcon name="i-lucide-arrow-right" class="size-5 text-muted group-hover:text-primary" />
          </div>
        </UCard>
      </NuxtLink>
    </div>
  </div>
</template>
