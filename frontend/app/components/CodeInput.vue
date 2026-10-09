<script setup lang="ts">
import type { CodeLanguage } from './CodeEditor.vue'

// Input of the field type "code": a code window - file name, language, copy - with the editor.
// Value: {language, file, code} or null (no code); plain text from before counts as code.
type CodeValue = { language: CodeLanguage, file: string | null, code: string }
const props = defineProps<{ disabled?: boolean }>()
const model = defineModel<CodeValue | string | null>()
const { t } = useI18n()

const LANGUAGES: { value: CodeLanguage, label: string, file: string }[] = [
  { value: 'html', label: 'HTML', file: 'snippet.html' },
  { value: 'twig', label: 'Twig', file: 'template.twig' },
  { value: 'css', label: 'CSS', file: 'style.css' },
  { value: 'javascript', label: 'JavaScript', file: 'script.js' },
  { value: 'php', label: 'PHP', file: 'example.php' },
  { value: 'json', label: 'JSON', file: 'data.json' },
  { value: 'markdown', label: 'Markdown', file: 'README.md' },
  { value: 'sql', label: 'SQL', file: 'query.sql' },
  { value: 'shell', label: 'Shell', file: 'terminal' },
  { value: 'text', label: t('code.text'), file: 'text.txt' },
]

const value = computed<CodeValue | null>(() => {
  const raw = model.value
  if (raw && typeof raw === 'object') return raw
  return typeof raw === 'string' && raw !== '' ? { language: 'text', file: null, code: raw } : null
})
// Language and file name stay while the code is empty (the value is then null)
const language = ref<CodeLanguage>(value.value?.language ?? 'html')
const file = ref(value.value?.file ?? '')
watch(value, (v) => {
  if (v) {
    language.value = v.language
    file.value = v.file ?? ''
  }
})
const code = computed({
  get: () => value.value?.code ?? '',
  set: (text: string) => update(text),
})
function update(text = code.value) {
  model.value = text.trim() === '' ? null : { language: language.value, file: file.value.trim() || null, code: text }
}
watch([language, file], () => { if (value.value) update() })

const lines = ref(1)
const copied = ref(false)
async function copy() {
  try {
    await navigator.clipboard.writeText(code.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1500)
  } catch { /* no clipboard */ }
}
</script>

<template>
  <div class="overflow-hidden rounded-md border border-default bg-white focus-within:ring-2 focus-within:ring-primary">
    <div class="flex flex-wrap items-center gap-2 border-b border-default bg-elevated/50 px-3 py-1.5">
      <span class="flex gap-1.5" aria-hidden="true"><i class="size-2.5 rounded-full bg-red-400" /><i class="size-2.5 rounded-full bg-amber-400" /><i class="size-2.5 rounded-full bg-emerald-400" /></span>
      <input
        v-model="file"
        type="text"
        spellcheck="false"
        :disabled="props.disabled"
        :aria-label="$t('code.file')"
        :placeholder="LANGUAGES.find(l => l.value === language)?.file"
        class="min-w-20 max-w-64 flex-1 bg-transparent py-1 font-mono text-xs font-semibold text-primary outline-none placeholder:text-dimmed"
      >
      <span class="ms-auto flex items-center gap-2">
        <span class="text-xs text-dimmed">{{ $t('code.lines', lines) }}</span>
        <USelect v-model="language" :items="LANGUAGES" size="xs" class="w-32" :disabled="props.disabled" :aria-label="$t('code.language')" />
        <UButton size="xs" color="neutral" variant="outline" :icon="copied ? 'i-lucide-check' : 'i-lucide-copy'" :label="copied ? $t('code.copied') : $t('code.copy')" :disabled="!code" @click="copy" />
      </span>
    </div>
    <CodeEditor v-model="code" :language="language" :disabled="props.disabled" min-height="10rem" max-height="32rem" bare @lines="lines = $event" />
  </div>
</template>
