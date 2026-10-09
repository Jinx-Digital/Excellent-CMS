<script setup lang="ts">
// Markdown fields: visual editor (Nuxt UI / TipTap) that reads and writes Markdown, or the source.
defineProps<{ disabled?: boolean }>()
const model = defineModel<string | null>()
const source = ref(false)

const text = computed<string>({
  get: () => model.value ?? '',
  set: (value) => { model.value = value.trim() === '' ? null : value }
})

const { t } = useI18n()
const items = computed(() => [
  [{ kind: 'undo', icon: 'i-lucide-undo-2', tooltip: { text: t('markdown.undo') } }, { kind: 'redo', icon: 'i-lucide-redo-2', tooltip: { text: t('markdown.redo') } }],
  [
    { kind: 'heading', level: 2, icon: 'i-lucide-heading-2', tooltip: { text: t('markdown.heading') } },
    { kind: 'heading', level: 3, icon: 'i-lucide-heading-3', tooltip: { text: t('markdown.subheading') } }
  ],
  [
    { kind: 'mark', mark: 'bold', icon: 'i-lucide-bold', tooltip: { text: t('markdown.bold') } },
    { kind: 'mark', mark: 'italic', icon: 'i-lucide-italic', tooltip: { text: t('markdown.italic') } },
    { kind: 'mark', mark: 'strike', icon: 'i-lucide-strikethrough', tooltip: { text: t('markdown.strike') } },
    { kind: 'mark', mark: 'code', icon: 'i-lucide-code', tooltip: { text: t('markdown.code') } }
  ],
  [
    { kind: 'bulletList', icon: 'i-lucide-list', tooltip: { text: t('markdown.list') } },
    { kind: 'orderedList', icon: 'i-lucide-list-ordered', tooltip: { text: t('markdown.orderedList') } },
    { kind: 'blockquote', icon: 'i-lucide-text-quote', tooltip: { text: t('markdown.quote') } },
    { kind: 'codeBlock', icon: 'i-lucide-square-code', tooltip: { text: t('markdown.codeBlock') } },
    { kind: 'horizontalRule', icon: 'i-lucide-separator-horizontal', tooltip: { text: t('markdown.rule') } }
  ]
] as const)
</script>

<template>
  <div class="rounded-md border border-default bg-default overflow-hidden" :class="{ 'opacity-75': disabled }">
    <UEditor
      v-if="!source"
      v-slot="{ editor }"
      v-model="text"
      content-type="markdown"
      :editable="!disabled"
      :placeholder="$t('markdown.placeholder')"
      class="min-h-40"
    >
      <div class="flex items-center gap-2 border-b border-default px-2 py-1">
        <UEditorToolbar v-if="!disabled" :editor="editor" :items="items as any" class="flex-1 overflow-x-auto" />
        <span v-else class="flex-1" />
        <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-file-code" label="Markdown" @click="source = true" />
      </div>
    </UEditor>
    <template v-else>
      <div class="flex items-center justify-between gap-2 border-b border-default px-2 py-1">
        <span class="text-xs text-muted px-1">{{ $t('markdown.source') }}</span>
        <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-eye" label="Editor" @click="source = false" />
      </div>
      <UTextarea v-model="text" autoresize :rows="8" variant="none" :disabled="disabled" class="w-full" :ui="{ base: 'font-mono text-sm p-4' }" />
    </template>
  </div>
</template>
