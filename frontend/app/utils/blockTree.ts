import type { Block, Field } from '~/types/api'

// Blocks at any depth of a blocks field (nested blocks: blocks inside the fields of a block, e.g.
// the content of a column). A path leads from the value of the field to a block:
// [1, 'columns', 0, 'content', 2] = the third block in the content of the first column of block 2.
export type BlockPath = (string | number)[]

/** Path of the block with this key - null if there is none */
export function findBlockPath(value: unknown, key: string, path: BlockPath = []): BlockPath | null {
  if (Array.isArray(value)) {
    for (let i = 0; i < value.length; i++) {
      const found = findBlockPath(value[i], key, [...path, i])
      if (found) return found
    }
    return null
  }
  if (!value || typeof value !== 'object') return null
  const object = value as Record<string, unknown>
  if (object._key === key && typeof object._type === 'string') return path
  for (const [name, inner] of Object.entries(object)) {
    if (name.startsWith('_') || !inner || typeof inner !== 'object') continue
    const found = findBlockPath(inner, key, [...path, name])
    if (found) return found
  }
  return null
}

export function getAt(value: unknown, path: BlockPath): unknown {
  return path.reduce<unknown>((current, step) => (current as Record<string | number, unknown> | null)?.[step], value)
}

/** A copy of the value with `next` at the path (only the objects on the way are copied) */
export function setAt(value: unknown, path: BlockPath, next: unknown): unknown {
  if (!path.length) return next
  const [step, ...rest] = path
  const current = Array.isArray(value) ? [...value] : { ...(value as Record<string, unknown>) }
  ;(current as Record<string | number, unknown>)[step!] = setAt((current as Record<string | number, unknown>)[step!], rest, next)
  return current
}

/**
 * The blocks field whose list holds the block at the path - the root field or one nested in a
 * block (null if the schema does not fit the value).
 */
export function blocksFieldAt(root: Field, value: unknown, path: BlockPath): Field | null {
  let field: Field | null = root
  let current: unknown = value
  // Everything but the index of the block in its list
  const steps = path.slice(0, -1)
  for (let i = 0; i < steps.length; i++) {
    const step = steps[i]!
    current = (current as Record<string | number, unknown> | null)?.[step]
    if (typeof step === 'number') {
      // An item of a list: a block (its group by _type) or an object of a repeatable group field
      const item = current as Record<string, unknown> | null
      const group: Field['group'] = field?.blocks?.length ? field.blocks.find(g => g.name === item?._type) ?? null : field?.group ?? null
      field = group ? { ...(field as Field), group, blocks: null } : null
    } else {
      // A field of the object (a group field that is not repeatable: its object, no index follows)
      field = field?.group?.fields.find(f => f.name === step) ?? null
    }
    if (!field) return null
  }
  return field?.blocks?.length ? field : null
}

/** A copy of a block with new keys - the blocks nested in it too */
export function copyBlock<T>(value: T): T {
  const rekey = (inner: unknown): unknown => {
    if (Array.isArray(inner)) return inner.map(rekey)
    if (!inner || typeof inner !== 'object') return inner
    const copy = Object.fromEntries(Object.entries(inner).map(([key, item]) => [key, rekey(item)]))
    return '_key' in copy && '_type' in copy ? { ...copy, _key: newBlock({ name: '', fields: [] })._key } : copy
  }
  return rekey(JSON.parse(JSON.stringify(value))) as T
}

/** A new, empty block of a group */
export function newBlock(group: { name: string, fields: Field[] }): Block {
  const key = Array.from(crypto.getRandomValues(new Uint8Array(6)), byte => byte.toString(16).padStart(2, '0')).join('')
  const block: Block = { _type: group.name, _key: key }
  for (const sub of group.fields) block[sub.name] = sub.type === 'boolean' ? false : null
  return block
}
