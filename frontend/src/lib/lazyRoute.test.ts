import { describe, expect, it } from 'vitest'
import { isChunkLoadFailure } from './lazyRoute'

/**
 * Telling "this page would not download" apart from "this page threw".
 *
 * Only the first is worth reloading for. Get it wrong in one direction and a
 * genuine bug turns into a reload loop; wrong in the other and every deploy
 * leaves open tabs showing an error screen.
 */
describe('isChunkLoadFailure', () => {
  it('recognises a chunk that 404d', () => {
    expect(isChunkLoadFailure(new TypeError(
      'Failed to fetch dynamically imported module: https://netvork.app/assets/MeetingRoomPage-Bgh69R-7.js',
    ))).toBe(true)
  })

  it('recognises a chunk that came back as something other than JavaScript', () => {
    // What a server does when a missing asset falls through to the SPA
    // fallback: index.html, with a 200, where a script was asked for.
    expect(isChunkLoadFailure(new Error(
      'error loading dynamically imported module: https://netvork.app/assets/MeetingRoomPage-Bgh69R-7.js',
    ))).toBe(true)
  })

  it('recognises the other engines', () => {
    expect(isChunkLoadFailure(new Error('Importing a module script failed.'))).toBe(true)
    expect(isChunkLoadFailure(new Error('ChunkLoadError: Loading chunk 42 failed.'))).toBe(true)
    expect(isChunkLoadFailure(new TypeError('Failed to fetch'))).toBe(true)
  })

  it('leaves real bugs alone, so they are never reloaded away', () => {
    expect(isChunkLoadFailure(new TypeError("Cannot read properties of undefined (reading 'name')"))).toBe(false)
    expect(isChunkLoadFailure(new Error('Rendered more hooks than during the previous render'))).toBe(false)
    expect(isChunkLoadFailure(new RangeError('Maximum call stack size exceeded'))).toBe(false)
    expect(isChunkLoadFailure(null)).toBe(false)
    expect(isChunkLoadFailure(undefined)).toBe(false)
  })
})
