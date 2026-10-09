<script setup lang="ts">
defineProps<{ title: string, subtitle?: string, back?: string }>()
</script>

<template>
  <div class="flex flex-wrap items-start gap-x-3 gap-y-4 mb-6">
    <UButton v-if="back" :to="back" icon="i-lucide-arrow-left" color="neutral" variant="ghost" class="-ms-2 shrink-0" :aria-label="$t('common.back')" />
    <div class="flex-1 min-w-[min(100%,16rem)]">
      <h1 class="text-2xl font-bold leading-tight truncate">{{ title }}</h1>
      <p v-if="subtitle" class="text-muted mt-1">{{ subtitle }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>
