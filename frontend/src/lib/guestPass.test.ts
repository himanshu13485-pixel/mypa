import { describe, expect, it } from 'vitest'
import { guestRouteFor, type GuestPass } from './guestPass'

/**
 * One invite link, for everybody.
 *
 * Members open /meetings/room/<code> and walk in. Everyone else has to be
 * routed somewhere useful rather than to a sign-in form they will not fill in,
 * and this decides where.
 */
const pass = (over: Partial<GuestPass> = {}): GuestPass => ({
  code: 'abc-defg-hij',
  token: 'tok',
  uuid: 'u-1',
  name: 'Prashant',
  expiresAt: new Date(Date.now() + 20 * 60_000).toISOString(),
  ...over,
})

describe('guestRouteFor', () => {
  it('sends a stranger on a meeting link to the guest door', () => {
    expect(guestRouteFor('/meetings/room/abc-defg-hij', null)).toBe('/join/abc-defg-hij')
  })

  it('carries the code across, so they are not asked to type it as well', () => {
    expect(guestRouteFor('/meetings/room/zzz-yyyy-xxx', null)).toBe('/join/zzz-yyyy-xxx')
  })

  it('tolerates the trailing slash a copied link often carries', () => {
    expect(guestRouteFor('/meetings/room/abc-defg-hij/', null)).toBe('/join/abc-defg-hij')
  })

  it('skips the door for someone already holding a pass — that is a reload', () => {
    expect(guestRouteFor('/meetings/room/abc-defg-hij', pass())).toBe('/guest/room/abc-defg-hij')
  })

  it('asks again once the half hour is up', () => {
    const stale = pass({ expiresAt: new Date(Date.now() - 60_000).toISOString() })
    expect(guestRouteFor('/meetings/room/abc-defg-hij', stale)).toBe('/join/abc-defg-hij')
  })

  it('does not let a pass for one meeting open another', () => {
    expect(guestRouteFor('/meetings/room/zzz-yyyy-xxx', pass())).toBe('/join/zzz-yyyy-xxx')
  })

  it('leaves every other deep link alone, so they still go to sign-in', () => {
    expect(guestRouteFor('/messages', null)).toBeNull()
    expect(guestRouteFor('/meetings', null)).toBeNull()
    expect(guestRouteFor('/', null)).toBeNull()
    // The list page, not a room — and not a code either.
    expect(guestRouteFor('/meetings/room/', null)).toBeNull()
    expect(guestRouteFor('/screen/session/abc-defg-hij', null)).toBeNull()
  })

  it('ignores anything that is not shaped like a meeting code', () => {
    expect(guestRouteFor('/meetings/room/../../admin', null)).toBeNull()
    expect(guestRouteFor('/meetings/room/abc-defg-hij/extra', null)).toBeNull()
    expect(guestRouteFor('/meetings/room/abcdefghij', null)).toBeNull()
  })

  it('forgives a retyped link — case, and a digit the generator may grow', () => {
    expect(guestRouteFor('/meetings/room/ABC-DEFG-HIJ', null)).toBe('/join/abc-defg-hij')
    expect(guestRouteFor('/meetings/room/abc-defg-hi0', null)).toBe('/join/abc-defg-hi0')
    // Lowercased before matching a pass, so the two can never disagree.
    expect(guestRouteFor('/meetings/room/ABC-DEFG-HIJ', pass())).toBe('/guest/room/abc-defg-hij')
  })
})
