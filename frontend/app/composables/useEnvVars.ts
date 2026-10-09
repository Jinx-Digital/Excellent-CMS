// Names of the .env variables settings can reference as $NAME (admins) - never their values.
// Loaded once and shared by every EnvInput.
export interface EnvVar { name: string, set: boolean }

export const useEnvVars = () => {
  const names = useState<EnvVar[] | null>('env-vars', () => null)
  if (names.value === null) {
    names.value = []
    useApi()<{ data: EnvVar[] }>('/admin/env-vars').then((res) => { names.value = res.data }).catch(() => {})
  }
  return names
}
