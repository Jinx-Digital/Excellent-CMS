<script setup lang="ts">
const { t } = useI18n()
useHead({ title: () => t('account.title') })
const { session, signedIn, loadSession } = useAuth()

// Password: the new one twice, the server checks again
const form = reactive({ current_password: '', new_password: '', new_password_confirmation: '' })
const { submit, saving, errors } = useSubmit()
const mismatch = computed(() => !!form.new_password_confirmation && form.new_password !== form.new_password_confirmation)

async function save() {
  const res = await submit(() => useApi()<{ data: { token: string } }>('/auth/change-password', { method: 'POST', body: form }), t('account.passwordChanged'))
  if (res) {
    signedIn.value = true
    Object.assign(form, { current_password: '', new_password: '', new_password_confirmation: '' })
  }
}

// E-mail address: only taken over when the link in the mail to the new address is opened
const emailForm = reactive({ email: '', password: '' })
const { submit: submitEmail, saving: savingEmail, errors: emailErrors } = useSubmit()
const pending = computed(() => session.value?.user.pending_email ?? null)

async function changeEmail() {
  const res = await submitEmail(() => useApi()('/auth/email', { method: 'POST', body: emailForm }), t('account.linkSent'))
  if (res) {
    Object.assign(emailForm, { email: '', password: '' })
    await loadSession()
  }
}

async function cancelEmail() {
  if (await submitEmail(() => useApi()('/auth/email', { method: 'DELETE' }), t('account.changeDiscarded'))) await loadSession()
}
</script>

<template>
  <div class="max-w-xl mx-auto space-y-6">
    <AppPageHeader :title="$t('account.title')" :subtitle="`${session?.user.name} · ${session?.user.email}`" />

    <form @submit.prevent="save">
      <UCard>
        <template #header><h2 class="font-semibold">{{ $t('account.changePassword') }}</h2></template>
        <div class="space-y-4">
          <UFormField :label="$t('account.currentPassword')" :error="errors.current_password"><UInput v-model="form.current_password" type="password" autocomplete="current-password" class="w-full" /></UFormField>
          <UFormField :label="$t('auth.newPassword')" :help="$t('auth.passwordHint')" :error="errors.new_password"><UInput v-model="form.new_password" type="password" autocomplete="new-password" class="w-full" /></UFormField>
          <UFormField :label="$t('auth.repeatPassword')" :error="mismatch ? $t('auth.passwordsDiffer') : errors.new_password_confirmation">
            <UInput v-model="form.new_password_confirmation" type="password" autocomplete="new-password" class="w-full" />
          </UFormField>
        </div>
        <template #footer>
          <div class="flex justify-end">
            <UButton type="submit" :loading="saving" :disabled="!form.current_password || !form.new_password || !form.new_password_confirmation || mismatch" :label="$t('account.changePassword')" />
          </div>
        </template>
      </UCard>
    </form>

    <form @submit.prevent="changeEmail">
      <UCard>
        <template #header><h2 class="font-semibold">{{ $t('account.changeEmail') }}</h2></template>
        <div class="space-y-4">
          <UAlert
            v-if="pending"
            color="info"
            variant="subtle"
            icon="i-lucide-mail-check"
            :title="$t('account.pending', { email: pending })"
            :description="$t('account.pendingHelp')"
            :actions="[{ label: $t('account.discard'), color: 'neutral', variant: 'outline', onClick: cancelEmail }]"
          />
          <UFormField :label="$t('account.newEmail')" :error="emailErrors.email"><UInput v-model="emailForm.email" type="email" autocomplete="email" class="w-full" /></UFormField>
          <UFormField :label="$t('account.currentPassword')" :help="$t('account.forSecurity')" :error="emailErrors.password"><UInput v-model="emailForm.password" type="password" autocomplete="current-password" class="w-full" /></UFormField>
        </div>
        <template #footer>
          <div class="flex justify-end"><UButton type="submit" :loading="savingEmail" :disabled="!emailForm.email || !emailForm.password" :label="$t('account.sendConfirmation')" /></div>
        </template>
      </UCard>
    </form>
  </div>
</template>
