<script setup lang="ts">
import type { RecordRef } from '~/types/api'

// Search field for reference values: shows the label of the target record, stores its id.
const props = defineProps<{ entity: string, initial?: RecordRef | null, disabled?: boolean }>()
const model = defineModel<string | null>()

const selected = computed<string | undefined>({
  get: () => model.value ?? undefined,
  set: (value) => { model.value = value ?? null }
})
const searchTerm = ref('')
const loading = ref(false)
const items = ref<{ id: string, label: string }[]>(props.initial ? [{ id: props.initial.id, label: props.initial.label }] : [])

async function load(term: string) {
  loading.value = true
  try {
    const res = await useApi()<{ data: { id: string, label: string }[] }>(`/entities/${props.entity}/options`, { query: { s: term } })
    let selected = items.value.find(i => i.id === model.value)
    // Value without known label (e.g. inside a field group): ask for it
    if (!selected && model.value && !res.data.some(i => i.id === model.value)) {
      selected = (await useApi()<{ data: { id: string, label: string }[] }>(`/entities/${props.entity}/options`, { query: { 'ids[]': model.value } })).data[0]
    }
    items.value = selected && !res.data.some(i => i.id === selected!.id) ? [selected, ...res.data] : res.data
  } finally {
    loading.value = false
  }
}

watchDebounced(searchTerm, term => load(term), { debounce: 250 })
onMounted(() => load(''))
</script>

<template>
  <div class="flex gap-2">
    <USelectMenu
      v-model="selected"
      v-model:search-term="searchTerm"
      :items="items"
      value-key="id"
      label-key="label"
      :loading="loading"
      :disabled="disabled"
      ignore-filter
      :placeholder="$t('common.chooseRecord')"
      class="flex-1"
    />
    <UButton v-if="model && !disabled" icon="i-lucide-x" color="neutral" variant="ghost" :aria-label="$t('common.clear')" @click="model = null" />
  </div>
</template>
