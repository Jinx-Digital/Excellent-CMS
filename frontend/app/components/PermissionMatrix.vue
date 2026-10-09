<script setup lang="ts">
import type { Entity, PermissionKey, Permissions } from '~/types/api'

// Permissions per entity (users, roles): writing implies reading, a column header toggles one
// permission for all entities. Inherited ones (from roles) are shown ticked and cannot be removed here.
const props = defineProps<{
  entities: Entity[]
  inherited?: Record<string, Partial<Permissions>>
  disabled?: boolean
}>()
const permissions = defineModel<Record<string, Permissions>>({ required: true })
const { t } = useI18n()

const KEYS: PermissionKey[] = ['read', 'create', 'update', 'update_own', 'delete', 'delete_own', 'import']
const columns = computed(() => KEYS.map(key => ({ key, label: t(`permissions.${key}`) })))
const empty = (): Permissions => ({ read: false, create: false, update: false, delete: false, import: false, update_own: false, delete_own: false })

const own = (entityId: string) => permissions.value[entityId] ?? empty()
const inheritedFrom = (entityId: string, key: PermissionKey) => !!props.inherited?.[entityId]?.[key]

function toggle(entityId: string, key: PermissionKey, value: boolean) {
  const next = { ...own(entityId), [key]: value }
  if (value && key !== 'read') next.read = true
  if (!value && key === 'read') KEYS.forEach((k) => { next[k] = false })
  permissions.value = { ...permissions.value, [entityId]: next }
}
function toggleColumn(key: PermissionKey) {
  const value = !props.entities.every(e => own(e.id)[key] || inheritedFrom(e.id, key))
  props.entities.forEach(e => toggle(e.id, key, value))
}
</script>

<template>
  <div class="overflow-x-auto" :class="{ 'opacity-50 pointer-events-none': disabled }">
    <table class="w-full text-sm">
      <thead class="bg-elevated/50">
        <tr>
          <th class="px-4 py-3 text-left">Entity</th>
          <th v-for="p in columns" :key="p.key" class="px-3 py-3">
            <button type="button" class="hover:text-primary" :title="$t('users.toggleAll', { permission: p.label })" @click="toggleColumn(p.key)">{{ p.label }}</button>
          </th>
        </tr>
      </thead>
      <tbody class="divide-y divide-default">
        <tr v-for="entity in entities" :key="entity.id">
          <td class="px-4 py-2 font-medium">{{ entity.name }}</td>
          <td v-for="p in columns" :key="p.key" class="px-3 py-2 text-center">
            <UCheckbox
              v-if="inheritedFrom(entity.id, p.key) && !own(entity.id)[p.key]"
              :model-value="true"
              disabled
              class="justify-center opacity-60"
              :title="$t('roles.inherited')"
            />
            <UCheckbox v-else :model-value="own(entity.id)[p.key]" class="justify-center" @update:model-value="toggle(entity.id, p.key, !!$event)" />
          </td>
        </tr>
      </tbody>
    </table>
    <EmptyState v-if="!entities.length" :text="$t('schema.noEntities')" />
  </div>
</template>
