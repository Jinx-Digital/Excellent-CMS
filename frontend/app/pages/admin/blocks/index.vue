<script setup lang="ts">
// Blocks: the types of items of block lists (page builder) - hero, text, image …
definePageMeta({ admin: true })
const { t } = useI18n()
useHead({ title: () => t('nav.blocks') })
</script>

<template>
  <GroupList kind="block" />
</template>
