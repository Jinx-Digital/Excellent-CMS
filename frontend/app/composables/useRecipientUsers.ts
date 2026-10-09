// Users as recipients of e-mail steps ("user:<id>"): the active users of the CMS and how a
// recipient reads - a user as "Name (email)", an address or placeholder as it is
export function useRecipientUsers() {
  const { data } = useAsyncData('recipient-users', () => useApi()<{ data: { id: string, name: string, email: string, is_active: boolean }[] }>('/admin/users', { query: { per_page: 200 } }).catch(() => ({ data: [] })))
  const users = computed(() => data.value?.data ?? [])
  const items = computed(() => users.value.filter(u => u.is_active).map(u => ({ value: `user:${u.id}`, label: u.name, description: u.email })))
  const label = (recipient: string) => {
    if (!recipient.startsWith('user:')) return recipient
    const user = users.value.find(u => u.id === recipient.slice(5))
    return user ? `${user.name} (${user.email})` : recipient
  }
  const list = (to: unknown) => (Array.isArray(to) ? to.map(String) : String(to ?? '').split(/[,;\s]+/)).map(s => s.trim()).filter(Boolean)
  return { items, label, list }
}
