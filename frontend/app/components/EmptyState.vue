<script setup lang="ts">
// Empty list - always in a card without padding (:ui="{ body: 'p-0 sm:p-0' }"), so every one has the same size
defineProps<{ icon?: string, text: string }>()
</script>

<template>
  <div class="text-center py-12 px-4 text-muted">
    <UIcon :name="icon || 'i-lucide-inbox'" class="size-12 mx-auto mb-3 opacity-60" />
    <p class="text-lg">{{ text }}</p>
    <div class="mt-4 flex justify-center">
      <slot />
    </div>
  </div>
</template>
