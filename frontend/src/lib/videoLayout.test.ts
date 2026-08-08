import { describe, expect, it } from 'vitest'
import { bestGalleryFit } from './videoLayout'

/**
 * The tile grid, which has been wrong twice.
 *
 * Once because it never measured its container at all — every tile drew at
 * the camera's own size, one enormous and one tiny, with a scrollbar in a
 * video call. Once because it pinned every tile to 16:9, which left two
 * thirds of an upright phone blank.
 *
 * Both were found by measuring a running browser. These are the same
 * measurements, without the browser.
 */
const PHONE = { w: 366, h: 648 }
const PHONE_LANDSCAPE = { w: 700, h: 320 }
const DESKTOP = { w: 1200, h: 640 }
const GAP = 8

/** Does the whole grid actually fit the box it was given? */
function fits(box: { w: number; h: number }, count: number) {
  const { cols, rows, width, height } = bestGalleryFit(box.w, box.h, count, GAP)
  return {
    cols,
    rows,
    width,
    height,
    holdsEveryone: cols * rows >= count,
    withinWidth: cols * width + GAP * (cols - 1) <= box.w + 0.5,
    withinHeight: rows * height + GAP * (rows - 1) <= box.h + 0.5,
  }
}

describe('bestGalleryFit', () => {
  it('gives nothing back before the container has been measured', () => {
    // The bug: a lobby renders before the tiles exist, so the first call
    // always has a zero box. Returning a size here is what produced tiles
    // laid out at the intrinsic size of the video.
    expect(bestGalleryFit(0, 0, 3)).toEqual({ cols: 0, rows: 0, width: 0, height: 0 })
    expect(bestGalleryFit(1200, 640, 0)).toEqual({ cols: 0, rows: 0, width: 0, height: 0 })
  })

  it.each([1, 2, 3, 4, 5, 6, 7, 8, 9])('fits %i people on a phone held upright', (count) => {
    const r = fits(PHONE, count)
    expect(r.holdsEveryone).toBe(true)
    expect(r.withinWidth).toBe(true)
    expect(r.withinHeight).toBe(true)
  })

  it.each([1, 2, 3, 4, 5, 6, 7, 8, 9])('fits %i people on a desktop', (count) => {
    const r = fits(DESKTOP, count)
    expect(r.holdsEveryone).toBe(true)
    expect(r.withinWidth).toBe(true)
    expect(r.withinHeight).toBe(true)
  })

  it.each([1, 2, 3, 4, 5, 6, 7, 8, 9])('fits %i people on a phone on its side', (count) => {
    const r = fits(PHONE_LANDSCAPE, count)
    expect(r.holdsEveryone).toBe(true)
    expect(r.withinWidth).toBe(true)
    expect(r.withinHeight).toBe(true)
  })

  it('gives one person the whole of an upright phone', () => {
    // This is the regression: 16:9 made it 366x206 with 442px left blank.
    const one = bestGalleryFit(PHONE.w, PHONE.h, 1, GAP)
    expect(one).toMatchObject({ cols: 1, rows: 1, width: 366, height: 648 })
  })

  it('stacks two people on an upright phone rather than shrinking them side by side', () => {
    const two = bestGalleryFit(PHONE.w, PHONE.h, 2, GAP)
    expect(two.cols).toBe(1)
    expect(two.rows).toBe(2)
    // Side by side each would have been 179px wide; stacked they are the
    // full width of the screen.
    expect(two.width).toBe(366)
  })

  it('keeps a desktop tile at 16:9', () => {
    for (const count of [2, 3, 4, 5, 6]) {
      const { width, height } = bestGalleryFit(DESKTOP.w, DESKTOP.h, count, GAP)
      expect(width / height).toBeCloseTo(16 / 9, 1)
    }
  })

  it('puts the deeper row first for an odd number, and the same both ways', () => {
    // Five on a desktop is three then two; upright it is the same
    // arrangement turned through ninety degrees.
    expect(bestGalleryFit(DESKTOP.w, DESKTOP.h, 5, GAP)).toMatchObject({ cols: 3, rows: 2 })
    expect(bestGalleryFit(PHONE.w, PHONE.h, 5, GAP)).toMatchObject({ cols: 2, rows: 3 })
  })

  it('caps the columns rather than making a row of nine', () => {
    expect(bestGalleryFit(DESKTOP.w, DESKTOP.h, 9, GAP).cols).toBeLessThanOrEqual(4)
    expect(bestGalleryFit(DESKTOP.w, DESKTOP.h, 16, GAP).cols).toBeLessThanOrEqual(4)
  })

  it('never returns a negative size, however cramped', () => {
    const tiny = bestGalleryFit(40, 30, 9, GAP)
    expect(tiny.width).toBeGreaterThanOrEqual(0)
    expect(tiny.height).toBeGreaterThanOrEqual(0)
  })
})
