import type { Permissions, Role } from '~/types/api'

/**
 * What the given roles allow together, contained roles included (entity id => permissions).
 */
export function rolePermissions(roles: Role[], slugs: string[]): Record<string, Partial<Permissions>> {
  const bySlug = Object.fromEntries(roles.map(role => [role.slug, role]))
  const result: Record<string, Partial<Permissions>> = {}
  const seen = new Set<string>()
  const queue = [...slugs]
  while (queue.length) {
    const role = bySlug[queue.shift()!]
    if (!role || seen.has(role.slug)) continue
    seen.add(role.slug)
    queue.push(...role.roles)
    for (const [entityId, permissions] of Object.entries(role.permissions)) {
      result[entityId] = { ...result[entityId] }
      for (const [key, value] of Object.entries(permissions)) {
        if (value) result[entityId]![key as keyof Permissions] = true
      }
    }
  }
  return result
}
