/**
 * Layout rules shared by every surface that shows video — meetings, personal
 * and group calls, and screen sessions.
 *
 * These started life inside the meeting room. Calls and screen sessions each
 * grew their own copy of the same ideas and the same mistakes, so a fix in one
 * place left the other two broken; keeping the rules here is what stops that
 * happening again.
 */
import { useCallback, useEffect, useRef, useState } from 'react'

/** The shape camera tiles are laid out to. */
const TILE_ASPECT = 16 / 9

export interface GalleryLayout {
  /** Columns that fit best; 0 until the container has been measured. */
  cols: number
  rows: number
  /** Pixel size of one tile. Zero until measured. */
  width: number
  height: number
}

/**
 * Works out the tile grid that fills a box best, the way Zoom and Meet do.
 *
 * Tailwind column classes cannot do this. They pick columns from the width of
 * the *window*, so they know nothing about the height available, and CSS grid
 * packs a short final row to the left — three people came out as two across
 * the top and one hanging off the left edge underneath, rather than centred.
 *
 * Measuring instead: for every possible column count, work out how large a
 * 16:9 tile could be and whether that many rows still fit the height. The
 * arrangement giving the biggest tiles wins. Laying those out in a centred
 * wrapping row then centres a short last row for free.
 */
export function useGalleryLayout(
  ref: React.RefObject<HTMLElement | null>,
  count: number,
  gap = 8,
): GalleryLayout {
  const [box, setBox] = useState({ w: 0, h: 0 })

  useEffect(() => {
    const el = ref.current
    if (!el) return
    const measure = () => setBox({ w: el.clientWidth, h: el.clientHeight })
    measure()
    const ro = new ResizeObserver(measure)
    ro.observe(el)
    return () => ro.disconnect()
  }, [ref])

  return bestGalleryFit(box.w, box.h, count, gap)
}

/**
 * The arrangement of `count` 16:9 tiles that fills a w x h box best.
 *
 * Separated from the hook so it can be checked directly: for each possible
 * column count, work out how large a tile could be and whether that many rows
 * still fit the height, then keep whichever gives the biggest tiles.
 */
export function bestGalleryFit(w: number, h: number, count: number, gap = 8): GalleryLayout {
  if (!count || w < 1 || h < 1) return { cols: 0, rows: 0, width: 0, height: 0 }

  /*
   * Columns follow a stated rule rather than a search.
   *
   * An even number of people makes a plain grid; an odd number fills the top
   * row one deeper than the bottom — five as three and two, seven as four and
   * three. Picking whichever arrangement produced the largest tiles was
   * cleverer and less predictable: the same meeting rearranged itself as the
   * window changed, which reads as broken even when every tile fits.
   *
   * Past eight the two-row rule gives absurdly wide rows, so columns cap and
   * rows grow instead.
   */
  const cols = count <= 2 ? count : Math.min(4, Math.ceil(count / 2))
  const rows = Math.ceil(count / cols)

  // Size to whichever runs out first, width or height, so the grid always
  // fits the box and nothing has to scroll or overlap.
  const byWidth = (w - gap * (cols - 1)) / cols
  const byHeight = ((h - gap * (rows - 1)) / rows) * TILE_ASPECT
  const width = Math.max(0, Math.floor(Math.min(byWidth, byHeight)))

  return { cols, rows, width, height: Math.floor(width / TILE_ASPECT) }
}

/**
 * How a video should sit in its tile: fit, never crop.
 *
 * A tile is rarely the camera's own shape — least of all with one person,
 * where the tile is as wide as the room — and cropping to fill it takes the
 * top and bottom off people. Shares and portrait phones always had to fit;
 * landscape cameras were the exception and no longer are.
 */
export const VIDEO_FIT = 'h-full w-full bg-black object-contain'

/**
 * Keeps a self-view attached to its stream across re-mounts.
 *
 * The stream used to be assigned straight onto the video element at each of
 * the moments it changes — joining, blur on and off, switching camera,
 * starting and stopping a share. That holds until the element is replaced, and
 * plenty of ordinary things replace it: switching layout moves the self tile
 * between the grid, the stage and the filmstrip, and turning the camera off
 * swaps the preview for a placeholder. React then mounts a fresh <video> with
 * an empty srcObject and nothing puts the stream back, so the tile goes black
 * while the track carries on being sent — it looks broken to you and fine to
 * everyone else.
 *
 * Remembering the stream and attaching through a callback ref fixes that: the
 * ref runs on every mount, so the picture comes back by itself.
 *
 *   const { show, attach } = useSelfView()
 *   ...
 *   show(stream)                     // whenever the stream changes
 *   <video ref={attach} autoPlay playsInline muted />
 */
export function useSelfView() {
  const elRef = useRef<HTMLVideoElement | null>(null)
  const streamRef = useRef<MediaStream | null>(null)

  const apply = (el: HTMLVideoElement | null, stream: MediaStream | null) => {
    if (!el || !stream || el.srcObject === stream) return
    el.srcObject = stream
    // A srcObject set after mount does not always start playback by itself.
    el.play().catch(() => undefined)
  }

  /** Point the self-view at a stream, and remember it for the next mount. */
  const show = useCallback((stream: MediaStream | null) => {
    streamRef.current = stream
    apply(elRef.current, stream)
  }, [])

  /** Ref for the <video>; re-attaches the remembered stream on every mount. */
  const attach = useCallback((el: HTMLVideoElement | null) => {
    elRef.current = el
    apply(el, streamRef.current)
  }, [])

  return { show, attach, videoRef: elRef, streamRef }
}
