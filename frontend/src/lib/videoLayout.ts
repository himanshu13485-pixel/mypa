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

/**
 * True on a phone-sized screen, and kept true as the window changes.
 *
 * Video surfaces cannot answer this with a Tailwind breakpoint alone: a phone
 * does not just want the same layout drawn smaller, it wants a different one —
 * one picture filling the screen with the other in the corner, the way every
 * phone calling app has worked since FaceTime.
 */
export function useIsPhone(): boolean {
  const [phone, setPhone] = useState(
    () => typeof window !== 'undefined' && window.matchMedia('(max-width: 639px)').matches,
  )
  useEffect(() => {
    const mq = window.matchMedia('(max-width: 639px)')
    const update = () => setPhone(mq.matches)
    update()
    mq.addEventListener('change', update)
    return () => mq.removeEventListener('change', update)
  }, [])
  return phone
}

/**
 * How wide or tall a tile is allowed to get.
 *
 * A tile is a window onto a video that is drawn `object-contain`, so its shape
 * costs nothing in distortion — only in wasted screen. Pinning every tile to
 * 16:9 wasted a great deal of it on a phone held upright: one person in a
 * 366x500 space got a 366x206 letterbox with 294px of empty room underneath,
 * and their portrait camera then letterboxed again inside that. Letting the
 * tile take the height it is offered, up to portrait, fills the screen.
 */
const WIDEST = 16 / 9
const TALLEST = 9 / 16

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
export function useGalleryLayout(count: number, gap = 8): GalleryLayout & {
  /** Put this on the container that holds the tiles. */
  attach: (el: HTMLElement | null) => void
} {
  const [box, setBox] = useState({ w: 0, h: 0 })
  const observer = useRef<ResizeObserver | null>(null)

  /*
   * A callback ref, not a ref object watched by an effect.
   *
   * The room shows a lobby before the tiles exist, so an effect that read
   * ref.current on mount found nothing, returned, and never observed anything
   * afterwards. The box stayed zero for the whole meeting, tiles were given no
   * size, and the browser laid them out at whatever size the video happened to
   * be — one enormous, one tiny, and a scrollbar. A callback ref runs when the
   * element actually appears, which is the only moment there is anything to
   * measure.
   */
  const attach = useCallback((el: HTMLElement | null) => {
    observer.current?.disconnect()
    observer.current = null
    if (!el) return

    const measure = () => {
      const w = el.clientWidth
      const h = el.clientHeight
      // Same size means the same object, or every measurement would re-render
      // and re-attach for ever.
      setBox((prev) => (prev.w === w && prev.h === h ? prev : { w, h }))
    }

    measure()
    const ro = new ResizeObserver(measure)
    ro.observe(el)
    observer.current = ro
  }, [])

  return { ...bestGalleryFit(box.w, box.h, count, gap), attach }
}

/**
 * The arrangement of `count` tiles that fills a w x h box best.
 *
 * Separated from the hook so it can be checked directly against numbers rather
 * than against a running browser.
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
  let cols = count <= 2 ? count : Math.min(4, Math.ceil(count / 2))
  let rows = Math.ceil(count / cols)

  /*
   * A phone held upright wants that grid stood on its end.
   *
   * The rule above is written for a window that is wider than it is tall. Used
   * as-is on a 366x648 phone, two people came out side by side — two narrow
   * strips using a third of the screen, with the rest blank. Swapping the two
   * numbers keeps exactly the same arrangement, and the same short last row,
   * turned through ninety degrees: two people stack, five go 2-2-1.
   */
  const upright = h > w
  if (upright) [cols, rows] = [rows, cols]

  const cellW = (w - gap * (cols - 1)) / cols
  const cellH = (h - gap * (rows - 1)) / rows

  // Width is whatever the cell allows, never so wide that a lone participant
  // on a monitor gets a letterbox slot.
  const width = Math.max(0, Math.floor(Math.min(cellW, cellH * WIDEST)))

  /*
   * Height is where the two shapes of screen part company.
   *
   * On anything wider than it is tall a tile stays 16:9 — the shape a webcam
   * sends, so the picture reaches the edges of its tile and the grid looks
   * tidy. That is the desktop and it is left exactly as it was.
   *
   * Upright, 16:9 is the wrong shape to be pinned to: it left two thirds of a
   * phone blank while the one face on the call sat in a strip across the
   * middle. There the tile takes the height it is offered, down to portrait,
   * and the video letterboxes inside it — which for a phone camera, itself
   * portrait, means no letterboxing at all.
   */
  const height = upright
    ? Math.max(0, Math.floor(Math.min(cellH, cellW / TALLEST)))
    : Math.floor(width / WIDEST)

  return { cols, rows, width, height }
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
