<script setup lang="ts">
import type { Field, MediaFile } from '~/types/api'

// Upload for media fields: files are stored at once (POST /media), the record saves their ids when
// it is saved. Replaced or removed files are deleted later by `./yii cleanup`.
// Repeatable media fields hold an ordered list: drag & drop or the arrow buttons change the order
// (if the field is sortable).
const props = defineProps<{ field: Field, entity?: string, disabled?: boolean }>()
const model = defineModel<MediaFile | MediaFile[] | string | null>()
const format = useFormat()

const input = ref<HTMLInputElement>()
const uploading = ref(false)
const error = ref<string | null>(null)

const multiple = computed(() => !!props.field.repeatable)
const sortable = computed(() => multiple.value && props.field.sortable !== false)
const files = computed<MediaFile[]>(() => {
  const value = model.value
  if (Array.isArray(value)) return value
  return value && typeof value === 'object' ? [value] : []
})
const max = computed(() => multiple.value ? props.field.repeat_max ?? null : 1)
const canAdd = computed(() => max.value === null || files.value.length < max.value || !multiple.value)
const accept = computed(() => props.field.media_accept ?? [])
const imagesOnly = computed(() => accept.value.length > 0 && accept.value.every(type => type.startsWith('image/')))
const { t } = useI18n()
const countHint = computed(() => {
  if (!multiple.value) return ''
  const { repeat_min: min, repeat_max: maxCount } = props.field
  if (min && maxCount) return t('media.range', { min, max: maxCount })
  if (maxCount) return t('media.atMost', maxCount)
  if (min) return t('media.atLeast', min)
  return ''
})

// Click on a file: its details of the media library (focal point, name …) over the form
const detailId = ref<string | null>(null)
function changed(file: MediaFile) {
  setFiles(files.value.map(f => f.id === file.id ? { ...f, name: file.name, focal_point: file.focal_point ?? null } : f))
}
function deleted(id: string) {
  setFiles(files.value.filter(f => f.id !== id))
}

function setFiles(list: MediaFile[]) {
  model.value = multiple.value ? list : (list[0] ?? null)
}

const details = (file: MediaFile) => {
  const size = file.size >= 1024 * 1024 ? `${format.number(file.size / 1024 / 1024, 1)} MB` : `${format.number(Math.max(1, Math.round(file.size / 1024)))} KB`
  return file.width && file.height ? `${file.width} × ${file.height} px · ${size}` : size
}

async function upload(event: Event) {
  const selected = Array.from((event.target as HTMLInputElement).files ?? [])
  if (!selected.length) return
  const room = max.value === null ? selected.length : multiple.value ? max.value - files.value.length : 1
  error.value = selected.length > room ? t('media.noRoom', room) : null
  uploading.value = true
  const uploaded: MediaFile[] = []
  try {
    for (const file of selected.slice(0, Math.max(room, 0))) {
      const body = new FormData()
      body.append('file', file)
      // The server checks the allowed types of the field right away
      if (props.entity) {
        body.append('entity', props.entity)
        body.append('field', props.field.name)
      }
      uploaded.push((await useApi()<{ data: MediaFile }>('/media', { method: 'POST', body })).data)
    }
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    setFiles(multiple.value ? [...files.value, ...uploaded] : (uploaded.length ? uploaded : files.value))
    uploading.value = false
    if (input.value) input.value.value = ''
  }
}

function remove(index: number) {
  setFiles(files.value.filter((_, i) => i !== index))
}

function move(from: number, to: number) {
  if (to < 0 || to >= files.value.length || from === to) return
  const list = [...files.value]
  const [item] = list.splice(from, 1)
  list.splice(to, 0, item!)
  setFiles(list)
}

// Files from the media library - the same file can be used in many records
const pickerOpen = ref(false)
const room = computed(() => max.value === null ? null : Math.max(max.value - (multiple.value ? files.value.length : 0), 1))
function pick(chosen: MediaFile[]) {
  const known = new Set(files.value.map(f => f.id))
  setFiles(multiple.value ? [...files.value, ...chosen.filter(f => !known.has(f.id))] : chosen.slice(0, 1))
}

// Native drag & drop
const drag = useDragSort(move)
const canSort = computed(() => sortable.value && !props.disabled && files.value.length > 1)
</script>

<template>
  <div class="space-y-2">
    <ul v-if="files.length" class="space-y-2">
      <li
        v-for="(file, index) in files"
        :key="file.id"
        class="flex items-center gap-3 rounded-md border border-default bg-default p-2 transition-colors"
        :class="drag.rowClass(index)"
        v-bind="drag.row(index, canSort)"
      >
        <DragHandle v-if="canSort" @move="by => drag.move(index, index + by, files.length)" />
        <button type="button" class="shrink-0 cursor-pointer rounded focus-visible:ring-2 focus-visible:ring-primary" draggable="false" :title="$t('media.details')" @click="detailId = file.id">
          <img v-if="file.is_image" :src="file.url" :alt="file.name" draggable="false" class="size-16 rounded object-cover bg-elevated">
          <span v-else class="size-16 rounded bg-elevated flex items-center justify-center">
            <UIcon name="i-lucide-file" class="size-7 text-muted" />
          </span>
        </button>
        <div class="min-w-0 flex-1 text-sm">
          <button type="button" draggable="false" class="block max-w-full cursor-pointer truncate text-start font-medium hover:text-primary" @click="detailId = file.id">{{ file.name }}</button>
          <div class="text-muted">{{ details(file) }}</div>
        </div>
        <div v-if="!disabled" class="flex shrink-0 items-center">
          <UButton icon="i-lucide-x" color="neutral" variant="ghost" size="sm" :aria-label="$t('media.remove')" @click="remove(index)" />
        </div>
      </li>
    </ul>
    <div v-if="!disabled" class="flex flex-wrap items-center gap-3">
      <UButton
        v-if="canAdd"
        :loading="uploading"
        icon="i-lucide-upload"
        color="neutral"
        variant="outline"
        :label="multiple ? (imagesOnly ? $t('media.addImages') : $t('media.addFiles')) : files.length ? $t('media.replace') : imagesOnly ? $t('media.uploadImage') : $t('media.uploadFile')"
        @click="input?.click()"
      />
      <UButton v-if="canAdd" icon="i-lucide-images" color="neutral" variant="ghost" :label="multiple || !files.length ? $t('media.fromLibrary') : $t('media.otherFromLibrary')" @click="pickerOpen = true" />
      <span v-if="countHint" class="text-sm text-muted">{{ $t('format.files', files.length) }} · {{ $t('repeat.allowed', { hint: countHint }) }}</span>
      <input ref="input" type="file" class="hidden" :multiple="multiple" :accept="accept.length ? accept.join(',') : undefined" @change="upload">
    </div>
    <p v-if="error" class="text-sm text-error">{{ error }}</p>
    <MediaPicker v-model:open="pickerOpen" :accept="accept" :multiple="multiple" :max="room" @select="pick" />
    <MediaDetail v-model:id="detailId" @changed="changed" @deleted="deleted" />
  </div>
</template>
