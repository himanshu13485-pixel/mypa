/**
 * Is this address the meeting room itself, rather than a page it floats over?
 *
 * The room is mounted twice - /meetings/room/<code> in the personal app and
 * /crm/<company>/connect/meetings/room/<code> under a company's shell - so the
 * test matches the tail rather than the whole path. Both shells and the page
 * slot in between read this one rule: when they disagree, the room either
 * draws in a corner of its own page or is pushed below a screenful of nothing.
 */
const MEETING_ROUTE = /\/meetings\/room\//

export function isMeetingRoute(pathname: string): boolean {
  return MEETING_ROUTE.test(pathname)
}
