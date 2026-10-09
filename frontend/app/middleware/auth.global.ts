import type { FetchError } from 'ofetch'

export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuth()
  // Composables must run before the first await (the Vue context is lost afterwards)
  const toast = useToast()

  if (to.meta.public) {
    return
  }

  if (!auth.isLoggedIn.value) {
    return navigateTo('/login')
  }

  if (!auth.session.value) {
    try {
      await auth.loadSession()
    } catch (error) {
      if ((error as FetchError).response?.status === 401) {
        return navigateTo('/login')
      }
      throw createError({ statusCode: 503, statusMessage: apiErrorMessage(error), fatal: true })
    }
  }

  if (to.meta.admin && !auth.isAdmin.value) {
    toast.add({ title: useNuxtApp().$i18n.t('errors.adminsOnly'), color: 'warning', icon: 'i-lucide-lock' })
    return navigateTo('/')
  }
})
