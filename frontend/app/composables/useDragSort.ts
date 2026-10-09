// Drag & drop for lists - the same everywhere (schema, fields, groups, blocks, steps, records):
// rows are dragged by their handle (DragHandle) - pressing it makes the row draggable, so inputs in
// the row keep working - and the handle moves them with the arrow keys too.
//
//   const drag = useDragSort((from, to) => save(moved(list, from, to)))
//   <li v-for="(item, index) in list" v-bind="drag.row(index)" :class="drag.rowClass(index)">
//     <DragHandle @move="step => drag.move(index, index + step, list.length)" />
export function useDragSort(onMove: (from: number, to: number) => void) {
  const dragIndex = ref<number | null>(null)
  const overIndex = ref<number | null>(null)
  const reset = () => {
    dragIndex.value = null
    overIndex.value = null
  }
  function move(from: number, to: number, length?: number) {
    if (from === to || to < 0 || (length !== undefined && to >= length)) return
    onMove(from, to)
  }
  /** Attributes and events of a row (enabled: false for lists that cannot be sorted) */
  function row(index: number, enabled = true) {
    if (!enabled) return {}
    return {
      'data-drag-row': '',
      onDragstart: (event: DragEvent) => {
        // Only the row of the pressed handle (nested lists: not its parents)
        if (event.target !== event.currentTarget) return
        event.stopPropagation()
        dragIndex.value = index
        if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
      },
      onDragover: (event: DragEvent) => {
        if (dragIndex.value === null) return
        event.preventDefault()
        event.stopPropagation()
        overIndex.value = index
      },
      onDragleave: () => {
        if (overIndex.value === index) overIndex.value = null
      },
      onDrop: (event: DragEvent) => {
        if (dragIndex.value === null) return
        event.preventDefault()
        event.stopPropagation()
        move(dragIndex.value, index)
        reset()
      },
      onDragend: (event: DragEvent) => {
        (event.currentTarget as HTMLElement).removeAttribute('draggable')
        reset()
      },
    }
  }
  /** Marks the dragged row and the row it would be dropped on */
  const rowClass = (index: number) => [
    dragIndex.value === index ? 'opacity-50' : '',
    overIndex.value === index && dragIndex.value !== index ? (dragIndex.value! < index ? 'shadow-[inset_0_-2px_0_var(--ui-primary)]' : 'shadow-[inset_0_2px_0_var(--ui-primary)]') : '',
  ]
  return { row, rowClass, move, dragIndex }
}

/** A copy of the list with the item moved */
export function moved<T>(list: readonly T[], from: number, to: number): T[] {
  const copy = [...list]
  const [item] = copy.splice(from, 1)
  copy.splice(to, 0, item!)
  return copy
}
