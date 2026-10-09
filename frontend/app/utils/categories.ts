// Blocks and field groups by category - categories in alphabetical order, the ones without one last
// (category null). Keeps the order of the items inside a category.
export function byCategory<T extends { category?: string | null }>(items: T[]): { category: string | null, items: T[] }[] {
  const groups = new Map<string | null, T[]>()
  for (const item of items) {
    const key = item.category?.trim() || null
    groups.set(key, [...(groups.get(key) ?? []), item])
  }
  return [...groups.entries()]
    .sort(([a], [b]) => a === null ? 1 : b === null ? -1 : a.localeCompare(b))
    .map(([category, list]) => ({ category, items: list }))
}

/** The categories in use - for suggestions */
export function categoriesOf(items: { category?: string | null }[]): string[] {
  return [...new Set(items.map(item => item.category?.trim()).filter((c): c is string => !!c))].sort((a, b) => a.localeCompare(b))
}
