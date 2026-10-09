<script setup lang="ts">
import type { ProjectVariable } from '~/types/api'

// Variables of the current project: {{name}} in text values of the records is filled in by the
// content API - translatable ones in the requested language.
definePageMeta({ admin: true })
const { t } = useI18n()
useHead({ title: () => t('nav.variables') })
const { project, loadSession } = useAuth()
const languages = computed(() => project.value?.languages ?? [])
const others = computed(() => languages.value.slice(1))

const { data } = await useAsyncData('admin-variables', () => useApi()<{ data: ProjectVariable[] }>('/admin/variables'))
const rows = ref<ProjectVariable[]>((data.value?.data ?? []).map(v => ({ ...v, translations: { ...v.translations } })))
const { submit, saving, errors } = useSubmit()

function add() {
  rows.value.push({ name: '', translatable: false, value: null, translations: {} })
}
function remove(index: number) {
  rows.value.splice(index, 1)
}
const error = (index: number, key: string) => errors.value[`variables.${index}.${key}`]

async function save() {
  const res = await submit(() => useApi()<{ data: ProjectVariable[] }>('/admin/variables', { method: 'PUT', body: { variables: rows.value } }), t('variables.saved'))
  if (res) {
    rows.value = res.data.map(v => ({ ...v, translations: { ...v.translations } }))
    await loadSession()
  }
}
</script>

<template>
  <form class="max-w-5xl mx-auto space-y-6" @submit.prevent="save">
    <AppPageHeader :title="$t('nav.variables')" :subtitle="$t('variables.subtitle', { project: project?.name ?? '' })">
      <template #actions>
        <UButton icon="i-lucide-plus" color="neutral" variant="outline" :label="$t('variables.add')" @click="add" />
        <UButton type="submit" icon="i-lucide-save" :loading="saving" :label="$t('common.save')" />
      </template>
    </AppPageHeader>

    <UAlert color="neutral" variant="subtle" icon="i-lucide-info" :title="$t('variables.example')">
      <template #description>
        <i18n-t keypath="variables.exampleText" tag="span">
          <template #variable><code>url</code></template>
          <template #value><code>https://example.com</code></template>
          <template #text><code v-pre>{{url}}/imprint</code></template>
          <template #result><code>https://example.com/imprint</code></template>
          <template #placeholder><code v-pre>{{name}}</code></template>
        </i18n-t>
      </template>
    </UAlert>

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <ul class="divide-y divide-default">
        <li v-for="(row, index) in rows" :key="index" class="p-4 space-y-3">
          <div class="flex items-end justify-between gap-3">
            <UFormField :label="$t('common.name')" :error="error(index, 'name')" class="w-full sm:w-72">
              <UInput v-model="row.name" placeholder="url" class="font-mono w-full">
                <template #leading><span class="text-muted" v-pre>{{</span></template>
                <template #trailing><span class="text-muted" v-pre>}}</span></template>
              </UInput>
            </UFormField>
            <div class="flex items-end pb-1">
              <UButton icon="i-lucide-trash-2" color="neutral" variant="ghost" :aria-label="$t('variables.remove')" @click="remove(index)" />
            </div>
          </div>
          <UFormField :label="row.translatable && languages.length ? $t('variables.valueIn', { language: languages[0]?.toUpperCase() }) : $t('variables.value')" :error="error(index, 'value')">
            <UInput :model-value="row.value ?? ''" class="w-full" @update:model-value="row.value = String($event) || null" />
          </UFormField>
          <template v-if="row.translatable">
            <UFormField v-for="code in others" :key="code" :label="$t('variables.valueIn', { language: code.toUpperCase() })" :help="$t('variables.emptyDefault')">
              <UInput :model-value="row.translations[code] ?? ''" class="w-full" @update:model-value="row.translations[code] = String($event) || null" />
            </UFormField>
          </template>
          <UCheckbox v-if="others.length" :id="`variable-${index}-translatable`" v-model="row.translatable" :label="$t('variables.translatable')" />
        </li>
      </ul>
      <EmptyState v-if="!rows.length" :text="$t('variables.empty')" />
    </UCard>
  </form>
</template>
