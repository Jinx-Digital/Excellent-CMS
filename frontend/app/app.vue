<script setup lang="ts">
import { de, en } from '@nuxt/ui/locale'

const { locale } = useI18n()
// Texts of Nuxt UI itself (date pickers, close buttons ...) in the language of the app
const uiLocale = computed(() => locale.value === 'de' ? de : en)

useHead({
  titleTemplate: title => title ? `${title} · Excellent CMS` : 'Excellent CMS',
  htmlAttrs: { lang: locale }
})
</script>

<template>
  <UApp :locale="uiLocale" :toaster="{ position: 'bottom-right', duration: 3500 }">
    <NuxtLoadingIndicator color="var(--ui-primary)" />
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </UApp>
</template>
