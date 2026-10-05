/**
 * The little formatting marks people already type into a chat.
 *
 * *bold*, _italic_, ~struck through~, `code` and ```a block of code```.
 * Nobody learns these: they arrive knowing them from every other
 * messenger, and type them whether or not the app does anything, so a
 * message reading "*urgent*" with the stars still showing is the app
 * failing to keep up rather than the person getting it wrong.
 *
 * The rules are the ones everywhere else uses, and they matter:
 *
 *   - A mark has to hug its text. "2 * 3 * 4" is arithmetic, and
 *     "a_b_c" is a variable name; neither is formatting.
 *   - A mark with nothing to close it stays as it was typed. Half a
 *     pair is a person who meant an asterisk.
 *   - Inside code nothing else applies, because the whole point of
 *     showing code is showing exactly what was written.
 *
 * This returns spans rather than HTML on purpose. The chat already turns
 * links and @names into real elements, and handing it a string of HTML
 * to insert would mean a message could carry markup of its own.
 */
export type Mark = 'bold' | 'italic' | 'strike' | 'code'

export interface Span {
  text: string
  marks: Mark[]
}

/** Longest first, so ``` is never read as ` followed by two more. */
const RULES: { mark: Mark; token: string; literal?: boolean }[] = [
  { mark: 'code', token: '```', literal: true },
  { mark: 'code', token: '`', literal: true },
  { mark: 'bold', token: '*' },
  { mark: 'italic', token: '_' },
  { mark: 'strike', token: '~' },
]

const isSpace = (c: string | undefined) => c === undefined || /\s/.test(c)

/*
 * A mark only counts at the edge of a word.
 *
 * Without this, get_user_id came out with "user" in italics - the
 * underscores in a variable name are not formatting, and somebody
 * pasting code into a chat should not have to think about it.
 */
const isWord = (c: string | undefined) => c !== undefined && /\w/.test(c)

/**
 * The first properly closed pair in this text, if there is one.
 *
 * Earliest wins, and where two start together the longer mark wins, so
 * ```code``` is a block rather than an empty inline one.
 */
function firstPair(text: string) {
  let best: { rule: typeof RULES[number]; start: number; end: number } | null = null

  for (const rule of RULES) {
    const { token } = rule
    let start = text.indexOf(token)

    while (start !== -1) {
      const afterOpen = text[start + token.length]
      /*
       * An opening mark hugs the text it opens, starts at the edge of a
       * word, and is not simply doubled - "***" is a row of stars
       * somebody typed, not an empty pair with a star inside it.
       */
      if (afterOpen !== undefined && !isSpace(afterOpen) && afterOpen !== token[0] && !isWord(text[start - 1])) {
        const end = text.indexOf(token, start + token.length + 1)
        const beforeClose = end === -1 ? undefined : text[end - 1]
        const afterClose = end === -1 ? undefined : text[end + token.length]

        if (end !== -1 && !isSpace(beforeClose) && beforeClose !== token[0] && !isWord(afterClose)) {
          if (!best || start < best.start || (start === best.start && token.length > best.rule.token.length)) {
            best = { rule, start, end }
          }
          break
        }
      }
      start = text.indexOf(token, start + 1)
    }
  }

  return best
}

/**
 * A message, split into the pieces it should be drawn as.
 *
 * Plain text comes back as one span with no marks, which is the common
 * case and costs nothing.
 */
export function spans(text: string, marks: Mark[] = []): Span[] {
  if (!text) return []

  const pair = firstPair(text)
  if (!pair) return [{ text, marks }]

  const { rule, start, end } = pair
  const before = text.slice(0, start)
  const inside = text.slice(start + rule.token.length, end)
  const after = text.slice(end + rule.token.length)
  const within = marks.includes(rule.mark) ? marks : [...marks, rule.mark]

  return [
    ...(before ? spans(before, marks) : []),
    // Inside code, what was typed is what is shown - marks and all.
    ...(rule.literal ? [{ text: inside, marks: within }] : spans(inside, within)),
    ...(after ? spans(after, marks) : []),
  ]
}

/**
 * The same message with its marks taken off, for the places that show a
 * line of it rather than the thing itself - a chat list, a notification,
 * a reply quoted above the box. Those have no room to be bold, and
 * leaving the stars in reads as clutter.
 */
export function plain(text: string): string {
  return spans(text).map((s) => s.text).join('')
}
