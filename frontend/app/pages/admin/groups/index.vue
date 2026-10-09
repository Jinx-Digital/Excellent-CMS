<script setup lang="ts">
// Field groups: reusable sets of fields (e.g. SEO = keywords + description) for group fields
definePageMeta({ admin: true })
const { t } = useI18n()
useHead({ title: () => t('nav.groups') })
</script>

<template>
  <GroupList kind="group" />
</template>
