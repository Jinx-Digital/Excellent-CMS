<script setup lang="ts">
// Language of the app (kept in a cookie, sent to the API as Accept-Language): a small button with
// the code, the names come in the menu
const { locale, locales, setLocale } = useI18n()

async function change(code: string) {
  if (code === locale.value) return
  await setLocale(code as typeof locale.value)
  // Texts from the API (messages, labels) come in the new language with the next request
  await refreshNuxtData()
}

const items = computed(() => locales.value.map(l => ({
  label: l.name ?? l.code,
  icon: l.code === locale.value ? 'i-lucide-check' : undefined,
  onSelect: () => change(l.code)
})))
</script>

<template>
  <UDropdownMenu :items="items" :content="{ align: 'end' }">
    <UButton icon="i-lucide-languages" :label="locale.toUpperCase()" color="neutral" variant="ghost" size="sm" class="shrink-0" :aria-label="$t('nav.language')" />
  </UDropdownMenu>
</template>
