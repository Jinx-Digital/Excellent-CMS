/** Common languages for projects (any ISO code like "de-at" can be typed in as well) */
export const LANGUAGES = ['de', 'en', 'fr', 'it', 'es', 'nl', 'pl', 'pt', 'cs', 'da', 'sv', 'no', 'fi', 'tr', 'ru', 'uk', 'el', 'hu', 'ro', 'ar', 'zh', 'ja']

/**
 * "German (de)" / "Deutsch (de)" - the name in the language of the admin app.
 */
export const languageName = (code: string, uiLocale = 'en') => {
  try {
    const name = new Intl.DisplayNames([uiLocale], { type: 'language' }).of(code)
    return name && name !== code ? `${name} (${code})` : code
  } catch {
    return code
  }
}
