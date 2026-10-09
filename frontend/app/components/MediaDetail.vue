<script setup lang="ts">
import type { LibraryFile } from '~/types/api'

// Details of a file of the media library in a modal: focal point, name, "keep", link, where it is
// used, delete (unused files). Used by the library and by media fields (click on a file there).
const id = defineModel<string | null>('id', { default: null })
const emit = defineEmits<{ changed: [file: LibraryFile], deleted: [id: string] }>()
const { t } = useI18n()
const { isAdmin } = useAuth()
const format = useFormat()
const toast = useToast()
const { submit } = useSubmit()

const selected = ref<LibraryFile | null>(null)
const open = computed({ get: () => !!id.value, set: (value) => { if (!value) id.value = null } })
watch(id, async (value) => {
  selected.value = null
  if (!value) return
  const res = await submit(() => useApi()<{ data: LibraryFile }>(`/media/${value}`), '')
  if (res && id.value === value) selected.value = res.data
  else if (!res) id.value = null
}, { immediate: true })

// Details: name and "keep in the library"
const nameDraft = ref('')
// Images: the focal point cropped variants keep in view
const focalDraft = ref<{ x: number, y: number } | null>(null)
watch(() => selected.value?.id, () => {
  nameDraft.value = selected.value?.name ?? ''
})
// Follows what is saved: another file, saving, removing (back to the middle)
watch(() => [selected.value?.id, JSON.stringify(selected.value?.focal_point ?? null)], () => {
  focalDraft.value = selected.value?.focal_point ? { ...selected.value.focal_point } : null
}, { immediate: true })
const focalChanged = computed(() => JSON.stringify(focalDraft.value) !== JSON.stringify(selected.value?.focal_point ?? null))
async function updateSelected(data: { name?: string, kept?: boolean, focal_point?: { x: number, y: number } | null }) {
  if (!selected.value) return
  const res = await submit(() => useApi()<{ data: LibraryFile }>(`/media/${selected.value!.id}`, { method: 'PATCH', body: data }), t('common.saved'))
  if (res) {
    selected.value = res.data
    emit('changed', res.data)
  }
}

const size = (bytes: number) => bytes >= 1024 * 1024 ? `${format.number(bytes / 1024 / 1024, 1)} MB` : `${format.number(Math.max(1, Math.round(bytes / 1024)))} KB`

async function copyUrl(file: LibraryFile) {
  await navigator.clipboard.writeText(file.url)
  toast.add({ title: t('library.linkCopied'), color: 'success', icon: 'i-lucide-copy' })
}

async function remove(file: LibraryFile) {
  if (await submit(() => useApi()(`/media/${file.id}`, { method: 'DELETE' }), t('library.deleted')) !== null) {
    id.value = null
    emit('deleted', file.id)
  }
}
</script>

<template>
  <UModal v-model:open="open" :title="selected?.name ?? ''" :ui="{ content: 'sm:max-w-2xl' }">
    <template v-if="selected" #body>
      <div class="space-y-5">
        <div v-if="selected.is_image && selected.transform_url" class="space-y-3 rounded-md bg-elevated/50 p-3">
          <FocalPointPicker v-model="focalDraft" :src="selected.url" :width="selected.width" :height="selected.height" />
          <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-muted">{{ $t('library.focalHelp') }}</p>
            <div class="flex gap-2">
              <UButton v-if="selected.focal_point" size="sm" color="neutral" variant="ghost" icon="i-lucide-x" :label="$t('library.focalRemove')" @click="updateSelected({ focal_point: null })" />
              <UButton size="sm" icon="i-lucide-crosshair" :label="$t('library.focalSave')" :disabled="!focalChanged" @click="updateSelected({ focal_point: focalDraft })" />
            </div>
          </div>
        </div>
        <a v-else :href="selected.url" target="_blank" rel="noopener" class="block rounded-md bg-elevated overflow-hidden">
          <img v-if="selected.is_image" :src="selected.url" :alt="selected.name" class="mx-auto max-h-80 object-contain">
          <div v-else class="py-10 flex flex-col items-center gap-2 text-muted">
            <UIcon name="i-lucide-file" class="size-12" />
            <span class="text-sm">{{ $t('library.openFile') }}</span>
          </div>
        </a>

        <div class="flex flex-wrap items-end gap-3">
          <UFormField :label="$t('common.name')" class="flex-1 min-w-48"><UInput v-model="nameDraft" class="w-full" /></UFormField>
          <UButton color="neutral" variant="outline" :label="$t('library.rename')" :disabled="!nameDraft || nameDraft === selected.name" @click="updateSelected({ name: nameDraft })" />
        </div>
        <USwitch
          :model-value="selected.kept"
          :label="$t('library.keep')"
          :description="$t('library.keepHelp')"
          @update:model-value="updateSelected({ kept: !!$event })"
        />
        <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
          <dt class="text-muted">{{ $t('library.type') }}</dt><dd class="font-mono">{{ selected.mime_type }}</dd>
          <dt class="text-muted">{{ $t('library.size') }}</dt><dd>{{ size(selected.size) }}<template v-if="selected.width && selected.height"> · {{ selected.width }} × {{ selected.height }} px</template></dd>
          <dt class="text-muted">{{ $t('library.uploadedAt') }}</dt><dd>{{ format.relative(selected.uploaded_at) }}</dd>
          <dt class="text-muted">{{ $t('library.link') }}</dt>
          <dd class="flex items-center gap-2 min-w-0">
            <span class="font-mono truncate">{{ selected.url }}</span>
            <UButton icon="i-lucide-copy" size="xs" color="neutral" variant="ghost" :aria-label="$t('library.copyLink')" @click="copyUrl(selected)" />
          </dd>
        </dl>

        <div>
          <h3 class="font-semibold mb-2">{{ $t('library.usedIn') }}</h3>
          <ul v-if="selected.usages.length" class="text-sm divide-y divide-default rounded-md border border-default">
            <li v-for="use in selected.usages" :key="`${use.entity}.${use.record_id}.${use.field}`" class="flex items-center justify-between gap-4 px-3 py-2">
              <NuxtLink v-if="!use.in_trash" :to="`/entities/${use.entity}/${use.record_id}`" class="text-primary hover:underline truncate">{{ use.record_label }}</NuxtLink>
              <span v-else class="truncate text-muted">{{ use.record_label }} <UBadge :label="$t('trash.title')" color="neutral" variant="subtle" size="sm" /></span>
              <span class="text-muted shrink-0">{{ use.entity_name }} · {{ use.field_label }}</span>
            </li>
          </ul>
          <p v-if="selected.hidden_usages" class="mt-2 text-sm text-muted">{{ $t('library.hiddenUsages', selected.hidden_usages) }}</p>
          <p v-if="!selected.usage_count" class="text-sm text-muted">{{ $t('library.nowhere') }}<template v-if="!selected.kept"> {{ $t('library.autoDelete') }}</template></p>
        </div>
      </div>
    </template>
    <template v-if="selected" #footer>
      <div class="flex justify-between gap-3 w-full">
        <ConfirmButton v-if="isAdmin && !selected.usage_count" :label="$t('common.delete')" icon="i-lucide-trash-2" :question="$t('library.deleteQuestion', { name: selected.name })" @confirm="remove(selected)" />
        <span v-else />
        <UButton :to="selected.url" target="_blank" icon="i-lucide-external-link" color="neutral" variant="outline" :label="$t('projects.open')" />
      </div>
    </template>
  </UModal>
</template>
