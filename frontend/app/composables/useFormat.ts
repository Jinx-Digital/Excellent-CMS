import type { Actor, ContentRecord, Field, FieldTypeKey, MediaFile, RecordRef } from '~/types/api'

export const FIELD_TYPE_ICONS: Record<FieldTypeKey, string> = {
  string: 'i-lucide-type',
  text: 'i-lucide-align-left',
  integer: 'i-lucide-hash',
  decimal: 'i-lucide-percent',
  boolean: 'i-lucide-toggle-left',
  date: 'i-lucide-calendar',
  datetime: 'i-lucide-calendar-clock',
  time: 'i-lucide-clock',
  email: 'i-lucide-at-sign',
  url: 'i-lucide-link',
  reference: 'i-lucide-arrow-right-left',
  uuid: 'i-lucide-fingerprint',
  autoincrement: 'i-lucide-list-ordered',
  media: 'i-lucide-image',
  slug: 'i-lucide-route',
  markdown: 'i-lucide-file-text',
  group: 'i-lucide-layers',
  regex: 'i-lucide-regex',
  order: 'i-lucide-grip-vertical',
  enum: 'i-lucide-list-checks',
  color: 'i-lucide-palette',
  phone: 'i-lucide-phone',
  daterange: 'i-lucide-calendar-range',
  json: 'i-lucide-braces',
  code: 'i-lucide-square-code'
}

/** All field types in the order of the forms (names: $t(`fieldTypes.${type}`)) */
export const FIELD_TYPES = Object.keys(FIELD_TYPE_ICONS) as FieldTypeKey[]

/**
 * Client-side check of a pattern (the API checks for real - PCRE and JavaScript differ in details).
 * null: no judgement possible.
 */
export const matchesPattern = (pattern: string | null | undefined, value: string): boolean | null => {
  if (!pattern || value === '') return null
  try {
    return new RegExp(pattern, 'u').test(value)
  } catch {
    return null
  }
}


/** Choices of UUID versions, recommended first */
export const uuidVersionItems = (t: (key: string) => string) => [7, 4, 6, 1].map(value => ({ value, label: t(`uuidVersions.v${value}`) }))

/** The database assigns these values: always unique, never required, not editable in forms */
export const isGeneratedType = (type: string) => type === 'autoincrement'

/**
 * Display of stored values in the formats of the chosen language.
 */
export const useFormat = () => {
  const { t, locale } = useI18n()
  const tag = () => locale.value === 'de' ? 'de-DE' : 'en-US'

  const number = (value: number, digits?: number) =>
    new Intl.NumberFormat(tag(), digits === undefined ? {} : { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value)

  // Dates are days without a time zone: formatted as UTC so they never shift
  const date = (value: string) => new Intl.DateTimeFormat(tag(), { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(value.slice(0, 10) + 'T00:00:00Z'))

  const dateTime = (value: string) => `${date(value)} ${value.slice(11, 16)}`
  // A moment with time zone (ISO 8601, e.g. scheduled publishing), in the browser's time
  const moment = (value: string) => new Intl.DateTimeFormat(tag(), { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))

  const relative = (value: string | null) => {
    if (!value) return '–'
    return new Intl.DateTimeFormat(tag(), { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value.replace(' ', 'T') + 'Z'))
  }

  /**
   * Text of a field value for tables and detail views.
   */
  const value = (field: Field, record: ContentRecord): string => {
    const raw = record[field.name]
    if (raw === null || raw === undefined || raw === '') return '–'
    // Values that are objects or lists themselves
    if (field.type === 'daterange' && !Array.isArray(raw)) {
      const range = raw as { from: string, to: string | null }
      return `${date(range.from)} – ${range.to ? date(range.to) : '…'}`
    }
    // Fields of plugins: what their value says about itself (label, name, address …), HTML as text
    if (field.type.includes('.')) {
      const object = raw && typeof raw === 'object' ? raw as Record<string, unknown> : null
      const text = object ? String(object.label ?? object.name ?? object.title ?? object.address ?? JSON.stringify(raw)) : String(raw).replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim()
      return text ? (text.length > 80 ? `${text.slice(0, 80)}…` : text) : '–'
    }
    // Code: the file name, or the first line
    if (field.type === 'code' && typeof raw === 'object' && !Array.isArray(raw)) {
      const code = raw as { file?: string | null, code?: string }
      const text = code.file || (code.code ?? '').trim().split('\n')[0] || ''
      return text ? (text.length > 60 ? `${text.slice(0, 60)}…` : text) : '–'
    }
    if (field.type === 'json') {
      const text = JSON.stringify(raw)
      return text.length > 60 ? `${text.slice(0, 60)}…` : text
    }
    // Groups: the first text inside, or the number of entries
    if (field.type === 'group') {
      if (Array.isArray(raw)) return raw.length ? t('format.items', raw.length) : '–'
      const first = Object.values(raw as Record<string, unknown>).find(v => typeof v === 'string' && v !== '')
      return first ? String(first) : '…'
    }
    // Lists: their values one after the other (references by their labels)
    if (Array.isArray(raw) && field.type !== 'media') {
      if (!raw.length) return '–'
      const refs = record._refs?.[field.name] as unknown as RecordRef[] | undefined
      return raw.map((item, index) => field.type === 'reference' ? (Array.isArray(refs) ? refs[index]?.label : null) ?? String(item) : value({ ...field, repeatable: false }, { ...record, [field.name]: item })).join(', ')
    }
    switch (field.type) {
      case 'boolean': return raw ? t('common.yes') : t('common.no')
      case 'decimal': return number(Number(raw), field.scale ?? 2)
      case 'integer':
      case 'order': return number(Number(raw))
      case 'enum': return (Array.isArray(raw) ? raw : [raw]).map(v => field.options?.find(o => o.value === v)?.label ?? String(v)).join(', ')
      case 'autoincrement': return String(raw)
      case 'date': return date(String(raw))
      case 'datetime': return dateTime(String(raw))
      case 'time': return String(raw).slice(0, 5)
      case 'reference': return record._refs?.[field.name]?.label ?? String(raw)
      case 'media':
        if (Array.isArray(raw)) return raw.length ? t('format.files', raw.length) : '–'
        return typeof raw === 'object' ? (raw as MediaFile).name : String(raw)
      default: return String(raw)
    }
  }

  /** Who did something: "Max Muster" or "API: Website" (deleted ones: "deleted user") */
  const actor = (value: Actor | null | undefined) => {
    if (!value) return '–'
    if (value.type === 'client') return `API: ${value.name ?? t('format.deletedClient')}`
    if (value.type === 'event') return `Event: ${value.name ?? t('format.deletedEvent')}`
    return value.name ?? t('format.deletedUser')
  }

  return { number, date, dateTime, moment, relative, value, actor }
}
