/**
 * Coming back to a list the way you left it.
 *
 * A list's filters used to live in the page's memory alone, so opening one
 * record and pressing Back rebuilt the list from nothing: the dates, the
 * statuses, the person it was narrowed to - all gone, and set up again by
 * hand for the next record, and the one after.
 *
 * Two halves. The list writes its filters into the address, so the address
 * itself is the list as it was. And it remembers that address for the rest
 * of the session, so a record's own Back button - which cannot know how it
 * was reached - still returns to it.
 */

const KEY = (list: string) => `list-return:${list}`

/** Note where this list is, filters and all. */
export function rememberList(list: string, url: string): void {
  try {
    sessionStorage.setItem(KEY(list), url)
  } catch {
    // Private mode or storage switched off: Back simply goes to the plain list.
  }
}

/** Where Back should go: the list as it was last seen, or the plain one. */
export function listReturnPath(list: string, fallback: string): string {
  try {
    return sessionStorage.getItem(KEY(list)) || fallback
  } catch {
    return fallback
  }
}

/*
 * A checkbox filter, in and out of the address.
 *
 * Three states, and all three have to survive the trip: null is "all" and
 * is left out entirely, a list is those values, and an empty list - every
 * box unticked, showing nothing - is written as "~", since an empty value
 * would read back as "all".
 */
export function listParamOf(value: string[] | null): string | null {
  if (value === null) return null

  return value.length ? value.join(',') : '~'
}

export function listFromParam(param: string | null): string[] | null {
  if (param === null) return null
  if (param === '~') return []

  return param.split(',').map((v) => v.trim()).filter(Boolean)
}
