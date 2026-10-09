<script setup lang="ts">
defineProps<{ to: string, icon: string, label: string, active?: boolean }>()
</script>

<template>
  <NuxtLink
    :to="to"
    class="flex items-center gap-3 h-10 px-3 rounded-lg text-sm font-medium transition-colors"
    :class="active ? 'bg-primary/10 text-primary' : 'text-default hover:bg-elevated'"
  >
    <UIcon :name="icon" class="size-5 shrink-0" />
    <span class="truncate">{{ label }}</span>
  </NuxtLink>
</template>
