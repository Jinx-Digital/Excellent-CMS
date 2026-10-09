<script setup lang="ts">
import type { FieldGroupDef } from '~/types/api'

// Choosing a block type: a search above the types, grouped by category (the trigger is the slot)
const props = defineProps<{ types: FieldGroupDef[] }>()
const emit = defineEmits<{ pick: [type: FieldGroupDef] }>()
const open = ref(false)
const { t } = useI18n()
const groups = computed(() => {
  const sections = byCategory(props.types)
  return sections.map(section => ({
    id: section.category ?? '-',
    label: sections.length > 1 || section.category ? section.category ?? t('groups.noCategory') : undefined,
    items: section.items.map(type => ({
      label: type.label,
      suffix: type.description ?? undefined,
      icon: 'i-lucide-square-plus',
      onSelect: () => {
        open.value = false
        emit('pick', type)
      },
    })),
  }))
})
</script>

<template>
  <UPopover v-model:open="open" :content="{ align: 'start', side: 'bottom' }">
    <slot />
    <template #content>
      <UCommandPalette :groups="groups" :placeholder="$t('preview.searchBlocks')" :empty="$t('preview.noBlocksFound')" class="max-h-80 w-80 max-w-[90vw]" />
    </template>
  </UPopover>
</template>
