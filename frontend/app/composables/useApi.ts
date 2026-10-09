import type { FetchError } from 'ofetch'
import type { ApiFailure } from '~/types/api'

const headers = () => {
  const project = useCurrentProject()
  // The login is the httpOnly cookie of the API - it counts only with this header (other sites cannot send it)
  const result: Record<string, string> = { 'X-Requested-With': 'excellent-admin' }
  // Every request of the admin app works in one project
  if (project.value) result['X-Project'] = project.value
  // Messages of the API in the language of the admin app
  const locale = useNuxtApp().$i18n?.locale?.value
  if (locale) result['Accept-Language'] = locale
  return result
}

const handleUnauthorized = (status?: number) => {
  const signedIn = useSignedIn()
  if (status === 401 && signedIn.value) {
    signedIn.value = false
    clearNuxtState('session')
    navigateTo('/login')
  }
}

/**
 * $fetch instance for the Yii3 API (Fixoo pattern): the login cookie goes along, logs out on 401.
 */
export const useApi = () => {
  const config = useRuntimeConfig()

  return $fetch.create({
    baseURL: config.public.apiBaseUrl,
    onRequest({ options }) {
      const merged = new Headers(options.headers as HeadersInit | undefined)
      for (const [key, value] of Object.entries(headers())) merged.set(key, value)
      options.headers = merged
    },
    onResponseError({ response }) {
      handleUnauthorized(response.status)
    }
  })
}

/**
 * Absolute URL of the API (shown in the API documentation).
 */
export const apiUrl = (path = '') => {
  const base = useRuntimeConfig().public.apiBaseUrl
  const origin = import.meta.client ? window.location.origin : ''
  return `${base.startsWith('http') ? '' : origin}${base}${path}`
}

/**
 * Turns any error into a readable message.
 */
export const apiErrorMessage = (error: unknown): string => {
  const fetchError = error as FetchError<ApiFailure>
  if (fetchError?.data?.error) return fetchError.data.error
  const t = useNuxtApp().$i18n.t
  if (fetchError?.name === 'FetchError' && !fetchError.response) return t('errors.offline')
  return t('errors.unknown')
}

export const apiErrorCode = (error: unknown): string | null =>
  (error as FetchError<ApiFailure>)?.data?.error_code ?? null

export const apiFieldErrors = (error: unknown): Record<string, string> => {
  const data = (error as FetchError<ApiFailure>)?.data?.error_data ?? {}
  // Usually a list of messages per field - a single message or nested ones (e.g. of a group) are flattened
  const text = (messages: unknown): string => typeof messages === 'string' ? messages
    : Array.isArray(messages) ? messages.map(text).join(' ')
      : messages && typeof messages === 'object' ? Object.values(messages).map(text).join(' ') : String(messages ?? '')
  return Object.fromEntries(Object.entries(data).map(([field, messages]) => [field, text(messages)]))
}

/**
 * Standard handling for form submits: field errors go next to the fields, everything else
 * into a toast. Returns null on failure.
 */
export const useSubmit = () => {
  const toast = useToast()
  const saving = ref(false)
  const errors = ref<Record<string, string>>({})
  // Field errors come from the API in the language of the request: after switching the language of
  // the admin app they would stay in the old one - they come back in the new one with the next save
  watch(useNuxtApp().$i18n.locale, () => {
    errors.value = {}
  })

  async function submit<T>(action: () => Promise<T>, success?: string): Promise<T | null> {
    saving.value = true
    errors.value = {}
    try {
      const result = await action()
      if (success !== '') toast.add({ title: success ?? useNuxtApp().$i18n.t('common.saved'), color: 'success', icon: 'i-lucide-check' })
      return result
    } catch (error) {
      errors.value = apiFieldErrors(error)
      toast.add({ title: apiErrorMessage(error), color: 'error', icon: 'i-lucide-circle-alert' })
      return null
    } finally {
      saving.value = false
    }
  }

  return { submit, saving, errors }
}
