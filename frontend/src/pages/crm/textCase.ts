/**
 * The same house style the server applies (App\Support\TextCase), mirrored
 * here so a field tidies itself the moment you leave it rather than only
 * after saving. The server remains the authority.
 */

const ALWAYS_UPPER = new Set([
  'LLC', 'LLP', 'PLC', 'INC', 'LLLP', 'PLLC',
  'NV', 'BV', 'SA', 'AG', 'AS', 'OY', 'AB',
  'FZE', 'FZCO', 'DMCC', 'JSC', 'PJSC', 'SARL', 'SRL', 'SPA',
  'UK', 'USA', 'UAE', 'HK', 'KSA',
])

/** Roman numerals are capitals wherever they appear: "Artis - II". */
const ROMAN = new Set(['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'])

const BRAND_ACRONYM_MAX = 5

const titleWord = (word: string) =>
  word.toLowerCase().replace(/(^|[-'’./])(\p{L})/gu, (_, sep: string, ch: string) => sep + ch.toUpperCase())

export function nameCase(value: string, brandAcronym = false): string {
  const trimmed = value.trim().replace(/\s+/g, ' ')
  if (!trimmed) return ''

  const isShouted = trimmed === trimmed.toUpperCase()

  return trimmed.split(' ').map((word, i) => {
    const letters = word.replace(/[^\p{L}]/gu, '')
    if (!letters) return word

    const bare = word.replace(/[^\p{L}\p{N}]/gu, '').toUpperCase()
    if (ALWAYS_UPPER.has(letters.toUpperCase()) || ROMAN.has(bare)) return word.toUpperCase()

    // Codes that mix letters and digits — B2B, 24X7 — are read as written.
    if (word === word.toUpperCase() && /\p{N}/u.test(word)) return word.toUpperCase()

    const isAcronym = word === word.toUpperCase() && letters.length <= BRAND_ACRONYM_MAX
    if (isAcronym && ((brandAcronym && i === 0) || !isShouted)) return word.toUpperCase()

    return titleWord(word)
  }).join(' ')
}

/** A company name: a short leading acronym is the brand and survives. */
export const companyCase = (value: string) => nameCase(value, true)

export const emailCase = (value: string) => value.trim().toLowerCase()

/** GSTIN, PAN, IFSC: capitals, no spaces. */
export const codeCase = (value: string) => value.replace(/\s+/g, '').toUpperCase()
