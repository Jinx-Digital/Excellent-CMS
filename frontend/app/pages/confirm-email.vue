<script setup lang="ts">
definePageMeta({ layout: 'blank', public: true })
const { t } = useI18n()
useHead({ title: () => t('auth.confirmEmail') })

// Works without login: the link may be opened in another browser or on the phone
const route = useRoute()
const { isLoggedIn, loadSession } = useAuth()
const token = String(route.query.token ?? '')
const state = ref<'loading' | 'done' | 'error'>(token ? 'loading' : 'error')
const email = ref('')
const error = ref(token ? '' : t('auth.linkIncomplete'))

onMounted(async () => {
  if (!token) return
  try {
    const res = await useApi()<{ data: { email: string } }>('/auth/email/confirm', { method: 'POST', body: { token } })
    email.value = res.data.email
    state.value = 'done'
    if (isLoggedIn.value) await loadSession().catch(() => {})
  } catch (e) {
    error.value = apiErrorMessage(e)
    state.value = 'error'
  }
})
</script>

<template>
  <UCard class="w-full max-w-sm">
    <div class="space-y-5">
      <h1 class="text-xl font-bold">{{ $t('auth.confirmEmail') }}</h1>
      <p v-if="state === 'loading'" class="text-sm text-muted">{{ $t('common.oneMoment') }}</p>
      <UAlert v-else-if="state === 'done'" color="success" variant="subtle" icon="i-lucide-circle-check" :title="$t('auth.confirmed')" :description="$t('auth.confirmedText', { email })" />
      <UAlert v-else color="error" variant="subtle" icon="i-lucide-circle-alert" :title="error" />
      <UButton :to="isLoggedIn ? '/account' : '/login'" block color="neutral" variant="outline" :label="isLoggedIn ? $t('auth.toAccount') : $t('auth.toSignIn')" />
    </div>
  </UCard>
</template>
