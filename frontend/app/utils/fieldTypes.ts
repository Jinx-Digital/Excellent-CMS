import type { Field, FieldTypeKey } from '~/types/api'

type Translate = (key: string) => string

/** Name of a field's type: the built-in one, or the label of the plugin's type ("Map position") */
export function typeLabel(field: Pick<Field, 'type' | 'custom'>, t: Translate): string {
  return field.type.includes('.') ? field.custom?.label ?? field.type : t(`fieldTypes.${field.type}`)
}

/** Icon of a field's type */
export function typeIcon(field: Pick<Field, 'type' | 'custom'>): string {
  return field.type.includes('.') ? field.custom?.icon ?? 'i-lucide-puzzle' : FIELD_TYPE_ICONS[field.type as FieldTypeKey] ?? 'i-lucide-circle'
}
