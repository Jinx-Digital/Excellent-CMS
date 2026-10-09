<script setup lang="ts">
// Confirmation only where it really matters (deleting).
// compact: on small screens only the icon (the label stays as aria-label)
const props = defineProps<{ label: string, question: string, confirmLabel?: string, icon?: string, color?: string, variant?: string, size?: string, block?: boolean, compact?: boolean }>()
const emit = defineEmits<{ confirm: [] }>()
const open = ref(false)

function confirm() {
  open.value = false
  emit('confirm')
}
</script>

<template>
  <UModal v-model:open="open" :title="props.label">
    <UButton :label="label" :aria-label="label" :icon="icon" :color="(color as any) || 'error'" :variant="(variant as any) || 'outline'" :size="(size as any) || 'md'" :block="block" :ui="compact ? { label: 'hidden sm:inline' } : undefined" />
    <template #body>
      <p>{{ question }}</p>
    </template>
    <template #footer>
      <div class="flex gap-3 w-full justify-end">
        <UButton color="neutral" variant="outline" :label="$t('common.cancel')" @click="open = false" />
        <UButton :color="(color as any) || 'error'" :label="confirmLabel || label" @click="confirm" />
      </div>
    </template>
  </UModal>
</template>
