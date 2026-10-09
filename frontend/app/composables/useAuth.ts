import type { PermissionKey, Session } from '~/types/api'

const cookieOptions = { path: '/', sameSite: 'lax' as const, maxAge: 60 * 60 * 24 * 7 }

/**
 * Signed in? Only a marker for the navigation - the login itself is an httpOnly cookie the API sets
 * (scripts in the page cannot read it, see SessionCookie in the API); the API decides on every request.
 */
export const useSignedIn = () => {
  const cookie = useCookie<string | null>('cms_signed_in', cookieOptions)
  const state = useState<boolean>('cms_signed_in', () => cookie.value === '1')
  // Logins of older versions kept the token readable in a cookie - gone (sign in once more)
  const legacy = useCookie<string | null>('cms_token', cookieOptions)
  if (legacy.value) legacy.value = null
  return computed<boolean>({
    get: () => state.value,
    set: (value) => {
      state.value = value
      cookie.value = value ? '1' : null
    }
  })
}

/**
 * Slug of the project the admin app works in (sent as X-Project with every request).
 */
export const useCurrentProject = () => {
  const cookie = useCookie<string | null>('cms_project', cookieOptions)
  const state = useState<string | null>('cms_project', () => cookie.value ?? null)
  return computed<string | null>({
    get: () => state.value,
    set: (value) => {
      state.value = value
      cookie.value = value
    }
  })
}

/**
 * Session state. Permissions always come from the server (/auth/me); the app only uses them to
 * hide what the user cannot use anyway - the API checks every request again.
 */
export const useAuth = () => {
  const signedIn = useSignedIn()
  const currentProject = useCurrentProject()
  const session = useState<Session | null>('session', () => null)
  const projects = computed(() => session.value?.projects ?? [])
  const project = computed(() => session.value?.project ?? null)

  const isLoggedIn = computed(() => signedIn.value)
  const isAdmin = computed(() => session.value?.user.is_admin === true)
  // Admins and editors take over records others are editing
  const canTakeOver = computed(() => isAdmin.value || session.value?.user.take_over === true)
  const entities = computed(() => session.value?.entities ?? [])
  const readable = computed(() => entities.value.filter(e => e.permissions.read))
  const canImport = computed(() => isAdmin.value || entities.value.some(e => e.permissions.import))

  const can = (permission: PermissionKey, slug: string) =>
    isAdmin.value || (entities.value.find(e => e.slug === slug)?.permissions[permission] ?? false)

  async function loadSession() {
    let res: { data: Session }
    try {
      res = await useApi()<{ data: Session }>('/auth/me')
    } catch (error) {
      // The remembered project is gone or not ours any more: start in the first one
      if (apiErrorCode(error) !== 'project_forbidden') throw error
      currentProject.value = null
      res = await useApi()<{ data: Session }>('/auth/me')
    }
    session.value = res.data
    currentProject.value = res.data.project?.slug ?? null
    return res.data
  }

  /**
   * Everything the app has loaded belongs to the old project - start over in the new one.
   */
  async function switchProject(slug: string) {
    currentProject.value = slug
    clearNuxtData()
    await loadSession()
    await navigateTo('/')
  }

  async function login(email: string, password: string) {
    const res = await useApi()<{ data: Session }>('/auth/login', { method: 'POST', body: { email, password } })
    signedIn.value = true
    session.value = { user: res.data.user, projects: res.data.projects, project: res.data.project, entities: res.data.entities }
    currentProject.value = res.data.project?.slug ?? null
  }

  async function logout() {
    await useApi()('/auth/logout', { method: 'POST' }).catch(() => {})
    signedIn.value = false
    session.value = null
    navigateTo('/login')
  }

  return { session, signedIn, isLoggedIn, isAdmin, canTakeOver, projects, project, entities, readable, canImport, can, loadSession, switchProject, login, logout }
}
