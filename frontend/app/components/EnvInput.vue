<script setup lang="ts">
// Input for secrets: a value, or a .env variable as $NAME (resolved when it is used, never stored).
// Typing "$" suggests the variables that may be used; "$$" starts a value that begins with "$".
// inline: for texts like URLs - "$NAME" anywhere in it (up to the first character that is no letter,
// digit or _), suggested when "$" is typed; "$$" is a "$" there too.
import type { EnvVar as EnvName } from '~/composables/useEnvVars'

const props = withDefaults(defineProps<{
  /** The variables to suggest - all of the .env by default */
  names?: EnvName[]
  placeholder?: string
  /** Hides a typed value (not a $NAME reference) */
  secret?: boolean
  /** $NAME variables inside the text instead of the whole value */
  inline?: boolean
}>(), { names: undefined, placeholder: undefined, secret: false, inline: false })
const envVars = useEnvVars()
const available = computed(() => props.names ?? envVars.value ?? [])

const model = defineModel<string>({ default: '' })
const { t } = useI18n()
const input = ref<{ inputRef?: HTMLInputElement } | null>(null)
const focused = ref(false)
const active = ref(0)
const revealed = ref(false)
const caret = ref(0)

const isReference = computed(() => !props.inline && model.value.startsWith('$') && !model.value.startsWith('$$'))
// Inline: the "$NA" right before the caret (not "$$", that is a "$")
const inlineMatch = computed(() => props.inline ? model.value.slice(0, caret.value).match(/(?:^|[^$])\$([A-Za-z0-9_]*)$/) : null)
const query = computed(() => props.inline ? (inlineMatch.value ? inlineMatch.value[1]!.toUpperCase() : null) : (isReference.value ? model.value.slice(1).toUpperCase() : null))
const suggestions = computed(() => {
  if (null === query.value) return []
  return available.value.filter(env => env.name.toUpperCase().includes(query.value!) && (props.inline || env.name !== model.value.slice(1))).slice(0, 8)
})
const open = computed(() => focused.value && suggestions.value.length > 0)
watch(suggestions, () => { active.value = 0 })

// Hints below the field: which variables are used, unknown or empty
const used = computed(() => props.inline
  ? [...new Set([...model.value.matchAll(/\$\$|\$([A-Za-z_][A-Za-z0-9_]*)/g)].map(m => m[1]).filter((name): name is string => !!name))]
  : (isReference.value ? [model.value.slice(1)] : []))
const hints = computed(() => used.value.map((name) => {
  const env = available.value.find(e => e.name === name)
  if (!env) return { name, color: 'text-error', text: t('envInput.unknown', { name }) }
  return env.set ? { name, color: 'text-success', text: t('envInput.used', { name }) } : { name, color: 'text-warning', text: t('envInput.empty', { name }) }
}))

const optionLabel = (env: EnvName) => `$${env.name}`

function trackCaret(event: Event) {
  caret.value = (event.target as HTMLInputElement).selectionStart ?? model.value.length
}

function choose(env: EnvName) {
  if (!props.inline) {
    model.value = `$${env.name}`
    return
  }
  const match = inlineMatch.value
  if (!match) return
  // Replaces the "$NA" before the caret, and the rest of a name right after it
  const start = caret.value - match[1]!.length - 1
  const rest = model.value.slice(caret.value).replace(/^[A-Za-z0-9_]+/, '')
  const inserted = `$${env.name}`
  model.value = model.value.slice(0, start) + inserted + rest
  const position = start + inserted.length
  caret.value = position
  nextTick(() => input.value?.inputRef?.setSelectionRange(position, position))
}

function onKeydown(event: KeyboardEvent) {
  if (!open.value) return
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    active.value = (active.value + (event.key === 'ArrowDown' ? 1 : -1) + suggestions.value.length) % suggestions.value.length
  } else if (event.key === 'Enter' || event.key === 'Tab') {
    event.preventDefault()
    choose(suggestions.value[active.value]!)
  } else if (event.key === 'Escape') {
    focused.value = false
  }
}
</script>

<template>
  <div class="relative w-full">
    <UInput
      ref="input"
      v-model="model"
      :type="secret && !isReference && !revealed ? 'password' : 'text'"
      :placeholder="placeholder"
      autocomplete="off"
      :class="['w-full', (isReference || inline) && 'font-mono']"
      @focus="focused = true"
      @blur="focused = false"
      @keydown="onKeydown"
      @input="trackCaret"
      @keyup="trackCaret"
      @click="trackCaret"
    >
      <template v-if="secret && !isReference && model" #trailing>
        <UButton :icon="revealed ? 'i-lucide-eye-off' : 'i-lucide-eye'" size="xs" color="neutral" variant="link" :aria-label="$t('envInput.reveal')" @click="revealed = !revealed" />
      </template>
    </UInput>
    <ul v-if="open" role="listbox" class="absolute z-50 mt-1 w-full max-h-60 overflow-auto rounded-md border border-default bg-default py-1 text-sm shadow-lg">
      <li
        v-for="(env, index) in suggestions"
        :key="env.name"
        role="option"
        :aria-selected="index === active"
        class="flex cursor-pointer items-center justify-between gap-2 px-3 py-1.5 font-mono"
        :class="index === active ? 'bg-elevated text-highlighted' : 'text-default'"
        @mousedown.prevent="choose(env)"
        @mouseenter="active = index"
      >
        <span>{{ optionLabel(env) }}</span>
        <span v-if="!env.set" class="font-sans text-xs text-warning">{{ $t('envInput.notSet') }}</span>
      </li>
    </ul>
    <p v-for="hint in hints" :key="hint.name" class="mt-1 text-xs" :class="hint.color">{{ hint.text }}</p>
  </div>
</template>
