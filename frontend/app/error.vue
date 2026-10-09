<script setup lang="ts">
import type { NuxtError } from '#app'

const props = defineProps<{ error: NuxtError }>()
const isNotFound = computed(() => props.error.statusCode === 404)
</script>

<template>
  <UApp>
    <div class="min-h-svh flex items-center justify-center p-4">
      <div class="w-full max-w-md text-center space-y-6">
        <UIcon :name="isNotFound ? 'i-lucide-map-pin-off' : 'i-lucide-wifi-off'" class="size-16 text-muted mx-auto" />
        <h1 class="text-2xl font-bold">{{ isNotFound ? $t('errors.notFoundTitle') : $t('errors.failedTitle') }}</h1>
        <p class="text-muted">{{ isNotFound ? $t('errors.notFoundText') : (error.statusMessage || $t('errors.tryAgain')) }}</p>
        <UButton :label="isNotFound ? $t('errors.toOverview') : $t('errors.retry')" @click="clearError({ redirect: '/' })" />
      </div>
    </div>
  </UApp>
</template>
