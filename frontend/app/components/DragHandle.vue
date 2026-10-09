<script setup lang="ts">
// Handle of a row that can be sorted by drag & drop (see useDragSort) - arrow keys move it too
defineProps<{ disabled?: boolean }>()
const emit = defineEmits<{ move: [step: -1 | 1] }>()
// Pressing the handle makes its row draggable (until the drag ends or the button is released)
const row = (event: Event) => (event.currentTarget as HTMLElement).closest<HTMLElement>('[data-drag-row]')
function arm(event: PointerEvent) {
  row(event)?.setAttribute('draggable', 'true')
}
function disarm(event: PointerEvent) {
  row(event)?.removeAttribute('draggable')
}
</script>

<template>
  <UButton
    icon="i-lucide-grip-vertical"
    color="neutral"
    variant="ghost"
    size="xs"
    class="cursor-grab text-dimmed active:cursor-grabbing"
    :disabled="disabled"
    :aria-label="$t('common.dragToSort')"
    :title="$t('common.dragToSort')"
    @pointerdown="arm"
    @pointerup="disarm"
    @keydown.up.prevent="emit('move', -1)"
    @keydown.down.prevent="emit('move', 1)"
  />
</template>
