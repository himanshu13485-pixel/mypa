import { useEffect, useRef, useState, type ReactNode } from 'react'
import { clsx } from 'clsx'

/**
 * A line that walks itself when it does not fit.
 *
 * The chat header carries a handle, a presence dot and "last seen an hour
 * ago". On a phone the end of that is off the edge, and making it swipeable
 * only works for somebody who thinks to swipe a subtitle — nobody does. So
 * it travels: right to left, a pause at each end, and round again.
 *
 * Only when it has to. The width is measured on mount and whenever the box
 * or the text changes, so a line with room to spare stays perfectly still
 * rather than drifting for no reason — movement in the corner of the eye is
 * a cost, and one paid for nothing is the worst kind.
 */
export default function Marquee({ children, className }: { children: ReactNode; className?: string }) {
  const boxRef = useRef<HTMLSpanElement>(null)
  const trackRef = useRef<HTMLSpanElement>(null)
  const [shift, setShift] = useState(0)

  useEffect(() => {
    const box = boxRef.current
    const track = trackRef.current
    if (!box || !track) return

    const measure = () => {
      // How far past the edge it runs. Zero means it fits, and a fitting
      // line is left alone.
      const over = track.scrollWidth - box.clientWidth
      setShift(over > 4 ? over : 0)
    }

    measure()

    /*
     * Re-measured rather than measured once: the presence dot appears when
     * somebody comes online and "last seen" arrives with it, so the line
     * this is asked about is rarely the line it was given at mount.
     */
    const observer = new ResizeObserver(measure)
    observer.observe(box)
    observer.observe(track)

    return () => observer.disconnect()
  }, [children])

  return (
    <span ref={boxRef} className={clsx('block overflow-hidden whitespace-nowrap', className)}>
      <span
        ref={trackRef}
        className={clsx(shift > 0 && 'marquee-track')}
        style={shift > 0
          ? ({
            '--marquee-shift': `${shift}px`,
            // About 40px a second, plus the pauses at either end: a walking
            // pace to read at rather than a number that happens to suit one
            // length of line.
            '--marquee-time': `${Math.round(shift / 40 + 5)}s`,
          } as React.CSSProperties)
          : undefined}
      >
        {children}
      </span>
    </span>
  )
}
