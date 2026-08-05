/**
 * Layout rules shared by every surface that shows video — meetings, personal
 * and group calls, and screen sessions.
 *
 * These started life inside the meeting room. Calls and screen sessions each
 * grew their own copy of the same ideas and the same mistakes, so a fix in one
 * place left the other two broken; keeping the rules here is what stops that
 * happening again.
 */
import { useCallback, useRef } from 'react'

/**
 * Columns for a gallery of n tiles, kept square-ish so nobody gets a sliver.
 */
export function galleryColumns(n: number): string {
  if (n <= 1) return 'grid-cols-1'
  if (n <= 2) return 'grid-cols-1 sm:grid-cols-2'
  if (n <= 4) return 'grid-cols-2'
  if (n <= 9) return 'grid-cols-2 lg:grid-cols-3'
  return 'grid-cols-3 lg:grid-cols-4'
}

/**
 * Row sizing, which is what keeps a gallery on screen.
 *
 * Tiles used to be fixed 16:9 boxes, so a tile's height came only from its
 * width and nothing knew how much vertical space existed. On a wide window a
 * single tile came out taller than the area holding it — you had to scroll to
 * see your own face — and several rows ran past the bottom and into each
 * other.
 *
 * Equal fractional rows divide the height that actually exists, so the gallery
 * always fits. Past nine people equal shares would leave faces unrecognisable,
 * so rows take a floor and the grid scrolls instead, as Meet does.
 */
export function galleryRows(n: number): string {
  return n <= 9 ? 'auto-rows-fr' : 'auto-rows-[minmax(9rem,1fr)]'
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
