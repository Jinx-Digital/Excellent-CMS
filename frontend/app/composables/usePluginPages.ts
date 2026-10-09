import type { PluginPage } from '~/types/api'

// Pages of the plugins active in the current project (menu "Extensions") - refreshNuxtData('plugin-pages') when plugins change
export function usePluginPages() {
  const { session, project } = useAuth()
  return useAsyncData('plugin-pages', () => session.value
    ? useApi()<{ data: PluginPage[] }>('/plugins/pages').then(res => res.data).catch(() => [] as PluginPage[])
    : Promise.resolve([] as PluginPage[]), { watch: [() => session.value?.user.id, () => project.value?.slug] })
}
