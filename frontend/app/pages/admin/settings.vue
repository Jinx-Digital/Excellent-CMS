<script setup lang="ts">
import type { RateLimit } from '~/types/api'

definePageMeta({ admin: true })
const { t } = useI18n()
useHead({ title: () => t('nav.rateLimit') })

const current = (await useApi()<{ data: RateLimit }>('/admin/settings/rate-limit')).data
const form = reactive({ ...current })
const { submit, saving, errors } = useSubmit()

async function save() {
  const res = await submit(() => useApi()<{ data: RateLimit }>('/admin/settings/rate-limit', { method: 'PUT', body: form }))
  if (res) Object.assign(form, res.data)
}
</script>

<template>
  <form class="max-w-5xl mx-auto space-y-6" @submit.prevent="save">
    <AppPageHeader :title="$t('nav.rateLimit')" :subtitle="$t('rateLimit.subtitle')" />
    <UCard>
      <div class="space-y-5">
        <USwitch id="rate-limit-enabled" v-model="form.enabled" :label="$t('rateLimit.enabled')" :description="$t('rateLimit.enabledHelp')" />
        <div class="grid gap-4 sm:grid-cols-2" :class="{ 'opacity-50': !form.enabled }">
          <UFormField :label="$t('rateLimit.requests')" :error="errors.requests"><UInput v-model.number="form.requests" type="number" min="1" :disabled="!form.enabled" /></UFormField>
          <UFormField :label="$t('rateLimit.window')" :error="errors.window"><UInput v-model.number="form.window" type="number" min="1" :disabled="!form.enabled" /></UFormField>
        </div>
        <i18n-t keypath="rateLimit.headers" tag="p" class="text-sm text-muted">
          <template #headers><code>X-RateLimit-Limit</code>, <code>X-RateLimit-Remaining</code>, <code>X-RateLimit-Reset</code></template>
          <template #status><code>429</code></template>
          <template #retry><code>Retry-After</code></template>
        </i18n-t>
      </div>
      <template #footer>
        <div class="flex justify-end"><UButton icon="i-lucide-save" type="submit" :loading="saving" :label="$t('common.save')" /></div>
      </template>
    </UCard>
  </form>
</template>
