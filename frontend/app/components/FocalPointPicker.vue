<script setup lang="ts">
// Focal point of an image (0 - 1 from the left and the top): a click sets it. The previews crop like
// the server does (cover): the part with the focal point as near the middle as the edges allow.
const props = defineProps<{ src: string, width: number | null, height: number | null }>()
const point = defineModel<{ x: number, y: number } | null>({ required: true })

const shown = computed(() => point.value ?? { x: 0.5, y: 0.5 })

function pick(event: MouseEvent) {
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const clamp = (value: number) => Math.round(Math.min(1, Math.max(0, value)) * 1000) / 1000
  point.value = { x: clamp((event.clientX - rect.left) / rect.width), y: clamp((event.clientY - rect.top) / rect.height) }
}

const PREVIEWS = [{ label: '16:9', ratio: 16 / 9 }, { label: '1:1', ratio: 1 }, { label: '3:4', ratio: 3 / 4 }]

// object-position of a cropped preview: which part of the image the server would keep
function position(ratio: number) {
  const imageRatio = props.width && props.height ? props.width / props.height : ratio
  // Share of the image the crop keeps, across and down
  const keepX = Math.min(1, ratio / imageRatio)
  const keepY = Math.min(1, imageRatio / ratio)
  const axis = (focal: number, keep: number) => keep >= 1 ? 50 : Math.min(1 - keep, Math.max(0, focal - keep / 2)) / (1 - keep) * 100
  return `${axis(shown.value.x, keepX)}% ${axis(shown.value.y, keepY)}%`
}
</script>

<template>
  <div class="space-y-3">
    <div class="relative mx-auto w-fit cursor-crosshair select-none" @click="pick">
      <img :src="src" alt="" class="block max-h-72 rounded-md" draggable="false">
      <span
        class="pointer-events-none absolute size-6 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-primary/40 shadow-[0_0_0_2px_rgb(0_0_0/0.35)] transition-[left,top]"
        :class="{ 'opacity-50': !point }"
        :style="{ left: `${shown.x * 100}%`, top: `${shown.y * 100}%` }"
      />
    </div>
    <div class="flex flex-wrap items-end justify-center gap-3">
      <figure v-for="preview in PREVIEWS" :key="preview.label" class="space-y-1 text-center">
        <div class="h-20 overflow-hidden rounded bg-elevated" :style="{ aspectRatio: String(preview.ratio) }">
          <img :src="src" alt="" class="size-full object-cover" :style="{ objectPosition: position(preview.ratio) }">
        </div>
        <figcaption class="text-xs text-muted">{{ preview.label }}</figcaption>
      </figure>
    </div>
  </div>
</template>
