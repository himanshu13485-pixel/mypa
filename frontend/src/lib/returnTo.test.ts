import { describe, expect, it } from 'vitest'
import { returnState, returnTo } from './returnTo'

describe('returnTo', () => {
  it('sends somebody back where they were going', () => {
    expect(returnTo({ from: '/meetings/room/abc-defg-hij' })).toBe('/meetings/room/abc-defg-hij')
  })

  it('keeps the query string, which carries the invite in some links', () => {
    expect(returnTo({ from: '/join/abc-defg-hij?expired=1' })).toBe('/join/abc-defg-hij?expired=1')
  })

  it('falls back to the dashboard when nobody said where to go', () => {
    expect(returnTo(undefined)).toBe('/')
    expect(returnTo(null)).toBe('/')
    expect(returnTo({})).toBe('/')
    expect(returnTo({ from: 42 })).toBe('/')
  })

  it('takes a caller-chosen fallback', () => {
    expect(returnTo(null, '/meetings')).toBe('/meetings')
  })

  /*
   * The whole reason this is a function and not a property read. A sign-in form
   * that forwards anywhere it is told is an open redirect, and the meeting
   * invite is exactly the kind of link that gets pasted around.
   */
  it('refuses to leave the site', () => {
    expect(returnTo({ from: 'https://evil.example/login' })).toBe('/')
    expect(returnTo({ from: '//evil.example' })).toBe('/')
    expect(returnTo({ from: '/\\evil.example' })).toBe('/')
    expect(returnTo({ from: 'javascript:alert(1)' })).toBe('/')
    expect(returnTo({ from: 'meetings/room/abc' })).toBe('/')
  })

  it('round-trips through the state it writes', () => {
    expect(returnTo(returnState('/meetings/room/abc-defg-hij'))).toBe('/meetings/room/abc-defg-hij')
  })
})
