import { useEffect, useRef, type ReactNode } from 'react'
import { clsx } from 'clsx'

/**
 * A table that stops being a table on a phone.
 *
 * Every list in the CRM is a wide table in a sideways scroller: fine on a
 * desk, useless in a hand. On a phone you saw three of eleven columns and
 * had to drag the row sideways to read the rest — so a lead was never on
 * screen all at once, and the column you had dragged away from was the one
 * you needed to compare against.
 *
 * Under `md` each row becomes a card instead: the columns stack, one line
 * each, and the row grows downwards until the whole record is on screen. No
 * sideways movement anywhere, which is the point — a vertical list scrolls
 * the way a phone already scrolls.
 *
 * The column headings are the labels. They are copied onto the cells here
 * rather than written twice in every page, because the alternative is two
 * hundred `data-label` attributes that go stale the first time somebody
 * renames a column. The observer keeps them right as rows are filtered,
 * paged and re-sorted.
 */
export default function TableBox({ children, className }: { children: ReactNode; className?: string }) {
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const box = ref.current
    if (!box) return

    const label = () => {
      const table = box.querySelector('table')
      if (!table) return

      const heads = [...table.querySelectorAll('thead th')].map((th) => (th.textContent ?? '').trim())

      for (const row of table.querySelectorAll('tbody tr')) {
        const cells = [...row.children] as HTMLTableCellElement[]
        // A row spanning the table is a message - "no leads yet" - and a
        // message with a column heading in front of it reads as a value.
        if (cells.length === 1 && cells[0].colSpan > 1) continue

        cells.forEach((cell, i) => {
          const heading = cell.colSpan > 1 ? '' : (heads[i] ?? '')
          // A checkbox or an icon column has no heading worth repeating.
          if (heading && cell.textContent?.trim()) cell.setAttribute('data-label', heading)
          else cell.removeAttribute('data-label')
        })
      }
    }

    label()

    /*
     * Re-labelled as the table changes.
     *
     * The rows are React's, and they come and go with every search, filter
     * and page. Watching the box is how the labels survive that without the
     * pages having to know this exists.
     */
    const watcher = new MutationObserver(label)
    watcher.observe(box, { childList: true, subtree: true })

    return () => watcher.disconnect()
  })

  return (
    <div ref={ref} className={clsx('table-box -mx-4 overflow-x-auto px-4', className)}>
      {children}
    </div>
  )
}
