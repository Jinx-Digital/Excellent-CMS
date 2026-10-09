<script setup lang="ts">
definePageMeta({ layout: 'blank', public: true })
const { t } = useI18n()
useHead({ title: () => t('auth.newPassword') })

const route = useRoute()
const { signedIn, loadSession } = useAuth()
const token = String(route.query.token ?? '')
const form = reactive({ password: '', password_confirmation: '' })
const loading = ref(false)
const error = ref('')
const errors = ref<Record<string, string>>({})
const mismatch = computed(() => !!form.password_confirmation && form.password !== form.password_confirmation)

async function submit() {
  loading.value = true
  error.value = ''
  errors.value = {}
  try {
    const res = await useApi()<{ data: { token: string } }>('/auth/password/reset', { method: 'POST', body: { token, ...form } })
    // Logged in right away
    signedIn.value = true
    await loadSession()
    await navigateTo('/')
  } catch (e) {
    errors.value = apiFieldErrors(e)
    error.value = Object.keys(errors.value).length ? '' : apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <UCard class="w-full max-w-sm">
    <form class="space-y-5" @submit.prevent="submit">
      <h1 class="text-xl font-bold">{{ $t('auth.setNewPassword') }}</h1>
      <UAlert v-if="!token" color="error" variant="subtle" icon="i-lucide-circle-alert" :title="$t('auth.linkIncomplete')" />
      <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" :title="error">
        <template #description><NuxtLink to="/forgot-password" class="underline">{{ $t('auth.requestNewLink') }}</NuxtLink></template>
      </UAlert>
      <UFormField :label="$t('auth.newPassword')" :help="$t('auth.passwordHint')" :error="errors.password">
        <UInput v-model="form.password" type="password" autocomplete="new-password" autofocus class="w-full" />
      </UFormField>
      <UFormField :label="$t('auth.repeatPassword')" :error="mismatch ? $t('auth.passwordsDiffer') : errors.password_confirmation">
        <UInput v-model="form.password_confirmation" type="password" autocomplete="new-password" class="w-full" />
      </UFormField>
      <UButton type="submit" block :loading="loading" :disabled="!token || !form.password || !form.password_confirmation || mismatch" :label="$t('auth.savePassword')" />
    </form>
  </UCard>
</template>
