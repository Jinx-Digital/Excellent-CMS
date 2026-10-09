<script setup lang="ts">
import type { Entity, Field } from '~/types/api'

// Developer documentation of the headless content API, built from the current schema.
const { t } = useI18n()
useHead({ title: () => t('nav.docs') })
const { data } = await useAsyncData('api-entities', () => useApi()<{ data: Entity[] }>('/entities'))
const entities = computed(() => (data.value?.data ?? []).filter(e => e.permissions?.read))
const selected = ref(entities.value[0]?.slug ?? '')
const entity = computed(() => entities.value.find(e => e.slug === selected.value))
const reference = computed(() => entity.value?.fields.find(f => f.type === 'reference'))
// Field of the referenced entity for the examples: its display field (the default for sorting)
const referenceField = computed(() => {
  const target = (data.value?.data ?? []).find(e => e.slug === reference.value?.reference)
  return target?.label_field ?? target?.fields.find(f => f.type === 'string')?.name ?? 'name'
})
// The content API of the current project
const { project } = useAuth()
const base = `${apiUrl()}/${project.value?.slug ?? 'main'}`
const auth = computed(() => entity.value?.access === 'oauth' ? ' \\\n  -H "Authorization: Bearer $TOKEN"' : '')

const examples = computed(() => {
  if (!entity.value) return []
  const e = entity.value
  const first = e.fields.find(f => f.type === 'string') ?? e.fields[0]
  return [
    { title: t('docs.ex.list'), code: `curl "${base}/content/${e.slug}?page=1&limit=25"${auth.value}` },
    { title: t('docs.ex.search'), code: `curl -g "${base}/content/${e.slug}?s=word&filter[${first?.name}][like]=abc&sort=-created_at"${auth.value}` },
    { title: t('docs.ex.fields'), code: `curl "${base}/content/${e.slug}?fields=${e.fields.slice(0, 3).map(f => f.name).join(',')}"${auth.value}` },
    ...(reference.value ? [
      { title: t('docs.ex.include', { field: reference.value.label }), code: `curl "${base}/content/${e.slug}?include=${reference.value.name}"${auth.value}` },
      { title: t('docs.ex.referencing', { entity: reference.value.reference ?? '' }), code: `curl -g "${base}/content/${e.slug}?filter[${reference.value.name}]=<id>"${auth.value}` },
      { title: t('docs.ex.filterReference', { entity: reference.value.reference ?? '' }), code: `curl -g "${base}/content/${e.slug}?filter[${reference.value.name}][${referenceField.value}]=abc"${auth.value}` },
      { title: t('docs.ex.sortReference', { field: reference.value.label }), code: `curl -g "${base}/content/${e.slug}?sort=${reference.value.name}"${auth.value}\ncurl -g "${base}/content/${e.slug}?sort=-${reference.value.name}[${referenceField.value}]"${auth.value}` }
    ] : []),
    { title: t('docs.ex.one'), code: `curl "${base}/content/${e.slug}/<id>"${auth.value}` },
    ...(e.fields.some(f => f.translatable) && (e.languages?.length ?? 0) > 1
      ? [
          { title: t('docs.ex.language', { language: e.languages![1]!, fallback: e.languages![0]! }), code: `curl "${base}/content/${e.slug}?lang=${e.languages![1]}"${auth.value}` },
          { title: t('docs.ex.allLanguages'), code: `curl "${base}/content/${e.slug}?lang=all"${auth.value}` }
        ]
      : []),
    ...(e.tree_field ? [{ title: t('docs.ex.tree'), code: `curl "${base}/content/${e.slug}?tree=1"${auth.value}` }] : [])
  ]
})

// Writing: example values per field type - filled in automatically are left out
const generated = (f: Field) => f.type === 'autoincrement' || f.type === 'uuid' || (f.type === 'slug' && !!f.slug_source)
function sample(f: Field, depth = 0): unknown {
  const single = (): unknown => {
    switch (f.type) {
      case 'integer': return 42
      case 'decimal': return 12.5
      case 'boolean': return true
      case 'date': return '2026-10-04'
      case 'datetime': return '2026-10-04 12:00:00'
      case 'time': return '12:00'
      case 'email': return 'name@example.com'
      case 'url': return 'https://example.com'
      case 'reference': return `<id of ${f.reference}>`
      case 'media': return '<id of a file of the media library>'
      case 'markdown': return '# Heading\n\nText with **Markdown**'
      case 'text': return 'A longer text'
      case 'slug': return 'my-record'
      case 'regex': return `<matching ${f.pattern}>`
      case 'group': return depth > 3 ? {} : Object.fromEntries((f.group?.fields ?? []).filter(sub => !generated(sub)).map(sub => [sub.name, sample(sub, depth + 1)]))
      default: return 'Text'
    }
  }
  return f.repeatable ? [single()] : single()
}
const writable = computed(() => (entity.value?.fields ?? []).filter(f => !generated(f)))
const bodyFor = (fields: Field[]) => JSON.stringify(Object.fromEntries(fields.map(f => [f.name, sample(f)])), null, 2)
const write = ' \\\n  -H "Authorization: Bearer $TOKEN"'
const writeExamples = computed(() => {
  if (!entity.value) return []
  const e = entity.value
  const required = writable.value.filter(f => f.required)
  const createFields = [...required, ...writable.value.filter(f => !f.required)].slice(0, Math.max(required.length, 4))
  const updateFields = writable.value.filter(f => !f.required).slice(0, 1).length ? writable.value.filter(f => !f.required).slice(0, 1) : writable.value.slice(0, 1)
  const translatable = e.fields.find(f => f.translatable && !generated(f))
  return [
    { title: t('docs.ex.create'), code: `curl -X POST "${base}/content/${e.slug}"${write} \\\n  -H "Content-Type: application/json" \\\n  -d '${bodyFor(createFields)}'` },
    { title: t('docs.ex.update'), code: `curl -X PATCH "${base}/content/${e.slug}/<id>"${write} \\\n  -H "Content-Type: application/json" \\\n  -d '${bodyFor(updateFields)}'` },
    ...(translatable && (e.languages?.length ?? 0) > 1
      ? [{ title: t('docs.ex.translation', { language: e.languages![1]! }), code: `curl -X PATCH "${base}/content/${e.slug}/<id>?lang=${e.languages![1]}"${write} \\\n  -H "Content-Type: application/json" \\\n  -d '${JSON.stringify({ [translatable.name]: sample(translatable) })}'` }]
      : []),
    { title: e.trash ? t('docs.ex.deleteTrash') : t('common.delete'), code: `curl -X DELETE "${base}/content/${e.slug}/<id>"${write}` }
  ]
})

// Field hints for the table
function hints(f: Field): string[] {
  const result: string[] = []
  if (f.required) result.push(t('fields.required'))
  if (f.unique) result.push(t('fields.unique'))
  if (f.repeatable) result.push(f.repeat_min || f.repeat_max ? t('docs.hint.listRange', { min: f.repeat_min ?? 0, max: f.repeat_max ?? '∞' }) : t('docs.hint.list'))
  if (f.translatable) result.push(t('fields.translatableShort'))
  if (f.reference) result.push(t('docs.hint.reference', { entity: f.reference }))
  if (f.type === 'media') result.push(t('docs.hint.media'))
  if (f.type === 'group') result.push(t('docs.hint.group', { fields: (f.group?.fields ?? []).map(sub => sub.name).join(', ') }))
  if (f.type === 'regex') result.push(t('docs.hint.pattern', { pattern: f.pattern ?? '' }))
  if (generated(f)) result.push(t('docs.hint.generated'))
  return result
}
</script>

<template>
  <div class="space-y-6">
    <AppPageHeader :title="$t('docs.title')" :subtitle="$t('docs.subtitle')" />

    <UCard>
      <template #header><h2 class="font-semibold">{{ $t('docs.access') }}</h2></template>
      <div class="space-y-3 text-sm">
        <p><AccessBadge access="public" /> {{ $t('docs.publicText') }}</p>
        <p><AccessBadge access="oauth" /> {{ $t('docs.oauthText') }}</p>
        <pre class="p-3 rounded-lg bg-elevated overflow-x-auto text-xs">curl -X POST {{ base }}/oauth/token \
  -d grant_type=client_credentials -d client_id=… -d client_secret=…

{"access_token": "…", "token_type": "Bearer", "expires_in": 3600, "scope": "…"}</pre>
        <!-- Texts of our own translation files with <code> inside -->
        <!-- eslint-disable-next-line vue/no-v-html -->
        <p class="text-muted" v-html="$t('docs.lists', { overview: `GET ${base}/content`, variables: `GET ${base}/variables`, placeholder: '{{url}}' })" />
        <i18n-t keypath="docs.sdk" tag="p" class="text-muted">
          <template #link><a href="https://github.com/Jinx-Digital/Excellent-CMS-PHP-SDK" target="_blank" rel="noopener" class="text-primary hover:underline">Excellent CMS PHP SDK</a></template>
          <template #install><code>composer require lugat/excellent-cms-php-sdk</code></template>
        </i18n-t>
      </div>
    </UCard>

    <UCard>
      <template #header><h2 class="font-semibold">{{ $t('docs.writing') }}</h2></template>
      <div class="space-y-3 text-sm">
        <!-- eslint-disable-next-line vue/no-v-html -->
        <p v-html="$t('docs.writingText')" />
        <ul class="list-disc ps-5 space-y-1 text-muted">
          <!-- eslint-disable vue/no-v-html -->
          <li v-html="$t('docs.writingChecks')" />
          <li v-html="$t('docs.writingPartial')" />
          <li v-html="$t('docs.writingGenerated')" />
          <li v-html="$t('docs.writingTranslations', { i18n: '&quot;_i18n&quot;: {&quot;title&quot;: {&quot;en&quot;: &quot;…&quot;}}' })" />
          <li v-html="$t('docs.writingMedia')" />
          <li v-html="$t('docs.writingDelete')" />
          <!-- eslint-enable vue/no-v-html -->
        </ul>
        <pre class="p-3 rounded-lg bg-elevated overflow-x-auto text-xs">HTTP 401  {{ $t('docs.status401') }}
HTTP 403  {{ $t('docs.status403') }}
HTTP 422  {{ $t('docs.status422') }}
{"status": "failed", "error": "Please check the marked fields.", "error_code": "validation",
 "error_data": {"title": ["Please fill in."], "price": ["Please enter a number."]}}</pre>
      </div>
    </UCard>

    <UCard>
      <template #header><h2 class="font-semibold">{{ $t('nav.media') }}</h2></template>
      <div class="space-y-3 text-sm">
        <!-- eslint-disable-next-line vue/no-v-html -->
        <p v-html="$t('docs.mediaText')" />
        <pre class="p-3 rounded-lg bg-elevated overflow-x-auto text-xs">curl -X POST "{{ base }}/media" \
  -H "Authorization: Bearer $TOKEN" \
  -F file=@logo.png -F entity=partner -F field=logo

HTTP 201 {"data": {"id": "1Cf…", "url": "https://…/media/…/1Cf….png", "name": "logo.png",
  "mime_type": "image/png", "size": 1234, "width": 400, "height": 300, "is_image": true, "kept": false}}

curl "{{ base }}/media/&lt;id&gt;" -H "Authorization: Bearer $TOKEN"      # {{ $t('docs.withUsage') }}
curl -X DELETE "{{ base }}/media/&lt;id&gt;" -H "Authorization: Bearer $TOKEN"</pre>
        <ul class="list-disc ps-5 space-y-1 text-muted">
          <!-- eslint-disable vue/no-v-html -->
          <li v-html="$t('docs.mediaField')" />
          <li v-html="$t('docs.mediaUnused')" />
          <li v-html="$t('docs.mediaDelete')" />
          <!-- eslint-enable vue/no-v-html -->
        </ul>
      </div>
    </UCard>

    <UCard v-if="entities.length">
      <template #header>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="font-semibold">{{ $t('docs.examples') }}</h2>
          <USelect v-model="selected" :items="entities.map(e => ({ value: e.slug, label: e.name }))" class="w-56" />
        </div>
      </template>
      <div v-if="entity" class="space-y-4">
        <div v-for="example in examples" :key="example.title">
          <div class="text-sm font-medium mb-1">{{ example.title }}</div>
          <pre class="p-3 rounded-lg bg-elevated overflow-x-auto text-xs">{{ example.code }}</pre>
        </div>
        <h3 class="pt-2 font-semibold">{{ $t('docs.writing') }}</h3>
        <div v-for="example in writeExamples" :key="example.title">
          <div class="text-sm font-medium mb-1">{{ example.title }}</div>
          <pre class="p-3 rounded-lg bg-elevated overflow-x-auto text-xs">{{ example.code }}</pre>
        </div>
        <div>
          <div class="text-sm font-medium mb-2">{{ $t('fields.title') }}</div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-left text-muted"><tr><th class="py-1 pe-4">{{ $t('common.name') }}</th><th class="py-1 pe-4">{{ $t('library.type') }}</th><th class="py-1">{{ $t('docs.hintColumn') }}</th></tr></thead>
              <tbody class="divide-y divide-default">
                <tr><td class="py-1 pe-4 font-mono">id</td><td class="py-1 pe-4">{{ $t('fieldTypes.string') }}</td><td class="py-1 text-muted">{{ $t('docs.idHint') }}</td></tr>
                <tr v-for="field in entity.fields" :key="field.name">
                  <td class="py-1 pe-4 font-mono">{{ field.name }}</td>
                  <td class="py-1 pe-4">{{ $t(`fieldTypes.${field.type}`) }}</td>
                  <td class="py-1 text-muted">{{ hints(field).join(' · ') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </UCard>
  </div>
</template>
