<script setup lang="ts">
definePageMeta({ layout: 'blank', public: true })
const { t } = useI18n()
useHead({ title: () => t('auth.forgotTitle') })

const email = ref('')
const loading = ref(false)
const error = ref('')
const sent = ref(false)

async function submit() {
  loading.value = true
  error.value = ''
  try {
    await useApi()('/auth/password/forgot', { method: 'POST', body: { email: email.value } })
    sent.value = true
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <UCard class="w-full max-w-sm">
    <div v-if="sent" class="space-y-5">
      <h1 class="text-xl font-bold">{{ $t('auth.checkInbox') }}</h1>
      <i18n-t keypath="auth.resetSent" tag="p" class="text-sm text-muted">
        <template #email><strong>{{ email }}</strong></template>
      </i18n-t>
      <UButton to="/login" block color="neutral" variant="outline" :label="$t('auth.toSignIn')" />
    </div>
    <form v-else class="space-y-5" @submit.prevent="submit">
      <div>
        <h1 class="text-xl font-bold">{{ $t('auth.forgotTitle') }}</h1>
        <p class="mt-1 text-sm text-muted">{{ $t('auth.forgotText') }}</p>
      </div>
      <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" :title="error" />
      <UFormField :label="$t('auth.email')">
        <UInput v-model="email" type="email" autocomplete="username" autofocus class="w-full" />
      </UFormField>
      <UButton type="submit" block :loading="loading" :disabled="!email" :label="$t('auth.sendLink')" />
      <NuxtLink to="/login" class="block text-center text-sm text-muted hover:text-primary">{{ $t('auth.backToSignIn') }}</NuxtLink>
    </form>
  </UCard>
</template>
