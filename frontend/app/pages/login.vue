<script setup lang="ts">
definePageMeta({ layout: 'blank', public: true })
const { t } = useI18n()
useHead({ title: () => t('auth.signIn') })

const auth = useAuth()
const state = reactive({ email: '', password: '' })
const loading = ref(false)
const error = ref('')

async function submit() {
  loading.value = true
  error.value = ''
  try {
    await auth.login(state.email, state.password)
    await navigateTo('/')
  } catch (e) {
    error.value = apiErrorMessage(e)
    state.password = ''
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <UCard class="w-full max-w-sm">
    <!-- Tab order: e-mail, password, sign in - the link "forgot password" (above the field) last -->
    <form class="space-y-5" @submit.prevent="submit">
      <h1 class="text-xl font-bold">{{ $t('auth.signIn') }}</h1>
      <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" :title="error" />
      <UFormField :label="$t('auth.email')">
        <UInput v-model="state.email" type="email" autocomplete="username" autofocus tabindex="1" class="w-full" />
      </UFormField>
      <UFormField :label="$t('auth.password')">
        <template #hint><NuxtLink to="/forgot-password" tabindex="4" class="text-sm text-primary hover:underline">{{ $t('auth.forgotLink') }}</NuxtLink></template>
        <UInput v-model="state.password" type="password" autocomplete="current-password" tabindex="2" class="w-full" />
      </UFormField>
      <UButton type="submit" block tabindex="3" :loading="loading" :disabled="!state.email || !state.password" :label="$t('auth.signIn')" />
    </form>
  </UCard>
</template>
