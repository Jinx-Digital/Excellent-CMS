<script setup lang="ts">
// Code editor (CodeMirror, light like the app): templates of blocks (Twig: HTML with {{ … }},
// {% … %}, {# … #} marked) and the field type "code". CodeMirror is loaded when the editor is shown.
import type { EditorView } from '@codemirror/view'
import type { Compartment, Extension } from '@codemirror/state'

export type CodeLanguage = 'html' | 'twig' | 'css' | 'javascript' | 'php' | 'json' | 'markdown' | 'sql' | 'shell' | 'text'

const props = withDefaults(defineProps<{
  language?: CodeLanguage
  placeholder?: string
  minHeight?: string
  maxHeight?: string
  disabled?: boolean
  /** Without its own border (inside a frame of its own, e.g. CodeInput) */
  bare?: boolean
}>(), { language: 'twig', placeholder: '', minHeight: '14rem', maxHeight: undefined, disabled: false, bare: false })
const model = defineModel<string>({ default: '' })
const emit = defineEmits<{ lines: [count: number] }>()

const host = ref<HTMLElement>()
// CodeMirror could not be loaded (e.g. offline, or a new version of the app): a plain text field instead
const failed = ref(false)
let view: EditorView | null = null
let languageSlot: Compartment | null = null
let editableSlot: Compartment | null = null
let languageOf: ((language: CodeLanguage) => Promise<Extension>) | null = null
let editableOf: ((on: boolean) => Extension) | null = null

onMounted(async () => {
  try {
    await start()
  } catch (error) {
    console.error('Code editor:', error)
    failed.value = true
  }
})

async function start() {
  const [{ EditorView: View, basicSetup }, { EditorState, Compartment: Slot }, { keymap, placeholder, Decoration, MatchDecorator, ViewPlugin }, { indentWithTab }] = await Promise.all([
    import('codemirror'), import('@codemirror/state'), import('@codemirror/view'), import('@codemirror/commands'),
  ])
  if (!host.value) return
  // Twig: tags marked on top of the HTML
  const twigMatcher = new MatchDecorator({ regexp: /\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\}/g, decoration: Decoration.mark({ class: 'cm-twig' }) })
  const twig = ViewPlugin.define(v => ({
    decorations: twigMatcher.createDeco(v),
    update(u) { this.decorations = twigMatcher.updateDeco(u, this.decorations) },
  }), { decorations: p => p.decorations })
  // The languages, loaded when needed
  languageOf = async (language) => {
    switch (language) {
      case 'html': return (await import('@codemirror/lang-html')).html()
      case 'twig': return [(await import('@codemirror/lang-html')).html(), twig]
      case 'css': return (await import('@codemirror/lang-css')).css()
      case 'javascript': return (await import('@codemirror/lang-javascript')).javascript({ jsx: true, typescript: true })
      case 'php': return (await import('@codemirror/lang-php')).php({ plain: false })
      case 'json': return (await import('@codemirror/lang-json')).json()
      case 'markdown': return (await import('@codemirror/lang-markdown')).markdown()
      case 'sql': return (await import('@codemirror/lang-sql')).sql()
      case 'shell': {
        const [{ StreamLanguage }, { shell }] = await Promise.all([import('@codemirror/language'), import('@codemirror/legacy-modes/mode/shell')])
        return StreamLanguage.define(shell)
      }
      default: return []
    }
  }
  editableOf = on => [View.editable.of(on), EditorState.readOnly.of(!on)]
  languageSlot = new Slot()
  editableSlot = new Slot()
  const languageExtension = await languageOf(props.language)
  if (!host.value) return
  view = new View({
    parent: host.value,
    state: EditorState.create({
      doc: model.value ?? '',
      extensions: [
        basicSetup,
        keymap.of([indentWithTab]),
        languageSlot.of(languageExtension),
        editableSlot.of(editableOf(!props.disabled)),
        ...(props.placeholder ? [placeholder(props.placeholder)] : []),
        View.lineWrapping,
        View.updateListener.of((update) => {
          if (update.docChanged) {
            model.value = update.state.doc.toString()
            emit('lines', update.state.doc.lines)
          }
        }),
        View.theme({
          '&': { fontSize: '12px', backgroundColor: 'transparent' },
          '&.cm-focused': { outline: 'none' },
          '.cm-scroller': { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace', minHeight: props.minHeight, ...(props.maxHeight ? { maxHeight: props.maxHeight } : {}) },
          '.cm-content': { padding: '8px 0' },
          '.cm-gutters': { backgroundColor: 'transparent', borderRight: '1px solid var(--ui-border)', color: 'var(--ui-text-dimmed)' },
          '.cm-activeLine, .cm-activeLineGutter': { backgroundColor: 'color-mix(in oklab, var(--ui-primary) 6%, transparent)' },
          '.cm-twig': { color: '#7c3aed', backgroundColor: 'color-mix(in oklab, #7c3aed 8%, transparent)', borderRadius: '3px' },
          '.cm-placeholder': { color: 'var(--ui-text-dimmed)' },
        }, { dark: false }),
      ],
    }),
  })
  emit('lines', view.state.doc.lines)
}

// Changed from outside (e.g. after loading): the editor follows
watch(model, (value) => {
  if (view && (value ?? '') !== view.state.doc.toString()) {
    view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: value ?? '' } })
  }
})
watch(() => props.language, async (language) => {
  if (view && languageSlot && languageOf) view.dispatch({ effects: languageSlot.reconfigure(await languageOf(language).catch(() => [])) })
})
watch(() => props.disabled, (disabled) => {
  if (view && editableSlot && editableOf) view.dispatch({ effects: editableSlot.reconfigure(editableOf(!disabled)) })
})

onBeforeUnmount(() => view?.destroy())
const reloadPage = () => location.reload()
</script>

<template>
  <div v-if="failed" class="space-y-1">
    <UTextarea v-model="model" autoresize :rows="8" :disabled="disabled" :placeholder="placeholder" spellcheck="false" class="w-full font-mono text-xs" />
    <p class="px-1 text-xs text-muted">{{ $t('code.editorFailed') }} <button type="button" class="text-primary hover:underline" @click="reloadPage">{{ $t('code.reload') }}</button></p>
  </div>
  <div v-else ref="host" class="overflow-auto bg-white text-gray-900" :class="bare ? '' : 'rounded-md border border-default focus-within:ring-2 focus-within:ring-primary'" />
</template>
