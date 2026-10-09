<script setup lang="ts">
// Media library › File types (admins): which types may be uploaded at all - by group, SVG to switch on
type MediaType = { type: string, extension: string, group: 'image' | 'document' | 'archive' | 'audio' | 'video', optional: boolean }
const open = defineModel<boolean>('open', { default: false })
const { t } = useI18n()
const { submit, saving, errors } = useSubmit()

const types = ref<MediaType[]>([])
const allowed = ref<string[]>([])
watch(open, async (value) => {
  if (!value) return
  const res = await useApi()<{ data: { types: MediaType[], allowed: string[] } }>('/admin/settings/media-types')
  types.value = res.data.types
  allowed.value = [...res.data.allowed]
}, { immediate: true })

const GROUPS = ['image', 'document', 'archive', 'audio', 'video'] as const
const byGroup = computed(() => GROUPS.map(group => ({ group, items: types.value.filter(type => type.group === group) })).filter(g => g.items.length))
const toggle = (type: string, on: boolean) => { allowed.value = on ? [...new Set([...allowed.value, type])] : allowed.value.filter(item => item !== type) }
const toggleGroup = (items: MediaType[], on: boolean) => items.forEach(item => toggle(item.type, on))

async function save() {
  if (await submit(() => useApi()('/admin/settings/media-types', { method: 'PUT', body: { allowed: allowed.value } }), t('common.saved')) !== null) open.value = false
}
</script>

<template>
  <UModal v-model:open="open" :title="$t('mediaTypes.title')" :description="$t('mediaTypes.help')" :ui="{ content: 'sm:max-w-2xl' }">
    <template #body>
      <div class="space-y-5">
        <section v-for="{ group, items } in byGroup" :key="group" class="space-y-2">
          <div class="flex items-center justify-between gap-2">
            <h3 class="font-semibold">{{ $t(`mediaTypes.groups.${group}`) }}</h3>
            <UCheckbox
              :model-value="items.every(item => allowed.includes(item.type)) ? true : items.some(item => allowed.includes(item.type)) ? 'indeterminate' : false"
              :label="$t('mediaTypes.all')"
              @update:model-value="toggleGroup(items, $event === true)"
            />
          </div>
          <div class="grid gap-2 sm:grid-cols-3">
            <UCheckbox
              v-for="item in items"
              :id="`media-type-${item.type}`"
              :key="item.type"
              :model-value="allowed.includes(item.type)"
              :label="item.extension.toUpperCase()"
              :description="item.optional ? $t('mediaTypes.svgHelp') : item.type"
              @update:model-value="toggle(item.type, !!$event)"
            />
          </div>
        </section>
        <p v-if="errors.allowed" class="text-sm text-error">{{ errors.allowed }}</p>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton color="neutral" variant="ghost" :label="$t('common.cancel')" @click="open = false" />
        <UButton icon="i-lucide-save" :loading="saving" :label="$t('common.save')" @click="save" />
      </div>
    </template>
  </UModal>
</template>
