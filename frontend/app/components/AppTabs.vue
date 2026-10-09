<script setup lang="ts">
// Tabs of a page (schema, record form …) - everywhere the same: above the cards, underlined, with
// a count or error badge if given
defineProps<{ items: { value: string, label: string, icon?: string, badge?: string | Record<string, unknown> }[] }>()
const model = defineModel<string>({ required: true })
</script>

<template>
  <UTabs v-model="model" :items="items" :content="false" variant="link" class="w-full" :ui="{ list: 'overflow-x-auto overflow-y-hidden' }" />
</template>
