/**
 * A description read as a list of keywords.
 *
 * "brass, steel, iron" is three things, not one sentence, and a work order
 * line that says so is easier to scan down a column than the same text run
 * together. So a comma-separated description is shown as separate keywords,
 * each in its own colour.
 *
 * What is stored does not change: the description is still one string, still
 * exactly what was typed, and still what prints on the document. This only
 * changes how it is read back — which is why prose is left alone. A sentence
 * with no comma in it is a sentence, and chopping it into a chip would be a
 * worse way to show it than plain text.
 */

/** How keywords are written back after one is removed. */
const SEPARATOR = ', '

/**
 * The keywords in a description, in the order they were typed.
 *
 * Newlines count as separators too. Somebody listing materials one per line
 * means the same thing as somebody listing them with commas, and it would be
 * odd for the display to agree with only one of them.
 *
 * Repeats are dropped, comparing case-insensitively but keeping the first
 * spelling — "Brass, brass" is one material typed twice, and showing it twice
 * only makes the line longer.
 */
export function splitKeywords(text: string): string[] {
  const seen = new Set<string>()
  const words: string[] = []

  for (const part of text.split(/[,\n]/)) {
    const word = part.trim()
    if (!word) continue

    const key = word.toLowerCase()
    if (seen.has(key)) continue

    seen.add(key)
    words.push(word)
  }

  return words
}

/** The description a list of keywords writes back to. */
export function joinKeywords(words: string[]): string {
  return words.join(SEPARATOR)
}

/**
 * Whether this description is a list at all.
 *
 * The comma decides, not the count. Somebody who types "brass," has told you
 * they are listing things and has so far named one of them — waiting for a
 * second before showing the first is the app disagreeing with them about
 * what they are doing.
 *
 * A separator is what makes it a list, so a description with none stays
 * plain text. That is the whole of the prose protection: "Annual
 * subscription including weekly updates" has no comma in it and is left
 * alone, where a lone chip around the sentence would read as a mistake.
 */
export function readsAsKeywords(text: string): boolean {
  return /[,\n]/.test(text) && splitKeywords(text).length > 0
}

/**
 * The colours a keyword can wear, light and dark.
 *
 * Six, spread around the wheel rather than gathered at the blue end — two
 * neighbouring hues in adjacent chips read as one colour, which defeats the
 * point. Emerald and red are missing on purpose: everywhere else in the CRM
 * they mean active and overdue, and a material called "brass" is neither.
 */
export const KEYWORD_TONES = [
  'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
  'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
  'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
  'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
  'bg-lime-100 text-lime-700 dark:bg-lime-500/15 dark:text-lime-300',
  'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300',
]

/** Which colour a word would prefer, decided by the word itself. */
function preferredTone(word: string): number {
  const key = word.trim().toLowerCase()
  let hash = 0

  for (let i = 0; i < key.length; i++) {
    // djb2, kept small enough to stay exact in a double for any real word.
    hash = (hash * 33 + key.charCodeAt(i)) % 1_000_003
  }

  return hash % KEYWORD_TONES.length
}

/**
 * The colour each keyword in one description wears.
 *
 * A word picks its colour from its own letters, so "steel" tends to be the
 * same colour on every line of every invoice and a column can be scanned for
 * it. But six colours and a hash means two words sometimes want the same one,
 * and two identical chips side by side is exactly what this was supposed to
 * fix — so within a single description a clash gives way and the later word
 * takes the next colour going free.
 *
 * Which is the right way round. Telling this line's keywords apart is the
 * thing being asked for; matching them across lines is a bonus that holds
 * whenever nothing is in the way.
 */
export function assignTones(words: string[]): string[] {
  const taken = new Set<number>()

  return words.map((word) => {
    let tone = preferredTone(word)

    for (let tried = 0; taken.has(tone) && tried < KEYWORD_TONES.length; tried++) {
      tone = (tone + 1) % KEYWORD_TONES.length
    }

    taken.add(tone)

    return KEYWORD_TONES[tone]!
  })
}
