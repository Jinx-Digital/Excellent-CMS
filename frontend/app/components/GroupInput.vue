<script setup lang="ts">
import type { Field } from '~/types/api'

// One object of a field group: an input for every field of the group (nested groups too).
const props = defineProps<{ field: Field, entity?: string, disabled?: boolean }>()
const model = defineModel<Record<string, unknown> | null>()

const fields = computed(() => props.field.group?.fields ?? [])
const value = (sub: Field) => model.value?.[sub.name] ?? (sub.type === 'boolean' ? false : null)
function set(name: string, value: unknown) {
  model.value = { ...(model.value ?? {}), [name]: value }
}
</script>

<template>
  <div class="rounded-md border border-default bg-elevated/30 p-3 space-y-4">
    <UFormField v-for="sub in fields" :key="sub.name" :label="sub.label" :required="sub.required" :hint="typeLabel(sub, $t)">
      <FieldInput :model-value="value(sub)" :field="sub" :entity="entity" :disabled="disabled" @update:model-value="set(sub.name, $event)" />
    </UFormField>
    <p v-if="!fields.length" class="text-sm text-muted">{{ $t('groups.noFields') }}</p>
  </div>
</template>
