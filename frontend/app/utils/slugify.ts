const GERMAN: Record<string, string> = { ä: 'ae', ö: 'oe', ü: 'ue', Ä: 'Ae', Ö: 'Oe', Ü: 'Ue', ß: 'ss' }

/**
 * Preview of the slug the API makes ("Über uns!" -> "ueber-uns"). The API is authoritative: it also
 * transliterates other scripts and adds "-2" etc. to slugs that are taken.
 */
export const slugify = (text: string, maxLength = 255): string => text
  .replace(/[äöüÄÖÜß]/g, char => GERMAN[char] ?? char)
  .normalize('NFKD')
  .replace(/[̀-ͯ]/g, '')
  .toLowerCase()
  .replace(/[^a-z0-9]+/g, '-')
  .replace(/^-+|-+$/g, '')
  .slice(0, maxLength)
  .replace(/-+$/, '')
