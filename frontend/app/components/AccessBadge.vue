<script setup lang="ts">
import type { AccessKey } from '~/types/api'

defineProps<{ access: AccessKey }>()
</script>

<template>
  <UBadge
    :color="access === 'public' ? 'success' : 'warning'"
    variant="subtle"
    :icon="access === 'public' ? 'i-lucide-globe' : 'i-lucide-key-round'"
    :label="access === 'public' ? $t('access.public') : $t('access.oauth')"
  />
</template>
