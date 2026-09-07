import { X } from 'lucide-react'
import { clsx } from 'clsx'
import { assignTones, joinKeywords, readsAsKeywords, splitKeywords } from '../lib/keywords'

/**
 * A comma-separated description, shown as the separate things it names.
 *
 * "brass, steel, iron" is a list, and a list is easier to read as a list. No
 * two keywords on a line wear the same colour, and a word tends to keep its
 * colour from line to line — so a column of work order lines can be scanned
 * for one material without reading every line of it.
 *
 * Draws nothing at all for prose. A description that was never meant as
 * keywords has no commas in it, and wrapping the whole sentence in one chip
 * would be a worse way to show it than the plain text already there.
 */
export function KeywordChips({
  text,
  onChange,
  className,
}: {
  text: string
  /** Given, each keyword can be dropped; otherwise they are only shown. */
  onChange?: (text: string) => void
  className?: string
}) {
  if (!readsAsKeywords(text)) return null

  const words = splitKeywords(text)
  const tones = assignTones(words)

  return (
    <div className={clsx('flex flex-wrap gap-1', className)}>
      {words.map((word, i) => (
        <span
          key={word.toLowerCase()}
          className={clsx(
            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
            tones[i],
          )}
        >
          {word}
          {onChange && (
            /*
             * Removing one here rather than making the writer find it in the
             * string and take the right comma with it. The box stays the
             * thing being edited — this is a shortcut, not a second editor.
             */
            <button
              type="button"
              aria-label={`Remove ${word}`}
              onClick={() => onChange(joinKeywords(words.filter((w) => w !== word)))}
              className="tap -mr-0.5 rounded-full opacity-60 hover:opacity-100"
            >
              <X className="size-3" />
            </button>
          )}
        </span>
      ))}
    </div>
  )
}
