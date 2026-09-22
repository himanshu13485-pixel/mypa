import { useEffect, useRef, useState } from 'react'
import { Bold, Code2, Eraser, Italic, Link2, List, ListOrdered, Underline } from 'lucide-react'
import { clsx } from 'clsx'

/**
 * The writing box for mail and signatures.
 *
 * A contentEditable area with the handful of formats mail actually uses -
 * bold, italic, underline, lists, links - and a Source view for anybody who
 * wants to paste or adjust the HTML itself. What it holds is HTML; the
 * server cleans it again before anything is sent.
 */
export default function RichEditor({ value, onChange, placeholder, minHeight = 220, autoFocus }: {
  value: string
  onChange: (html: string) => void
  placeholder?: string
  minHeight?: number
  autoFocus?: boolean
}) {
  const box = useRef<HTMLDivElement>(null)
  const [source, setSource] = useState(false)

  // Only written into the box when it changed from outside - rewriting it on
  // every keystroke would throw the caret back to the start.
  useEffect(() => {
    if (box.current && box.current.innerHTML !== value) box.current.innerHTML = value
  }, [value, source])

  useEffect(() => {
    if (autoFocus) box.current?.focus()
  }, [autoFocus])

  const run = (command: string, arg?: string) => {
    box.current?.focus()
    // Deprecated in the specification, supported in every browser, and the
    // only way to format a contentEditable without a large editor library.
    document.execCommand(command, false, arg)
    onChange(box.current?.innerHTML ?? '')
  }

  const link = () => {
    const url = window.prompt('Link address (https://…)')
    if (url && /^(https?:|mailto:)/i.test(url.trim())) run('createLink', url.trim())
  }

  const tool = (label: string, icon: React.ReactNode, onClick: () => void, active = false) => (
    <button
      type="button"
      title={label}
      aria-label={label}
      onMouseDown={(e) => e.preventDefault()}
      onClick={onClick}
      className={clsx(
        'rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-700 dark:hover:text-slate-100',
        active && 'bg-slate-100 text-slate-800 dark:bg-slate-700 dark:text-slate-100',
      )}
    >
      {icon}
    </button>
  )

  return (
    <div className="rounded-xl ring-1 ring-inset ring-slate-200 dark:ring-slate-700">
      <div className="flex flex-wrap items-center gap-0.5 border-b border-slate-100 px-1.5 py-1 dark:border-slate-800">
        {!source && (
          <>
            {tool('Bold', <Bold className="size-4" />, () => run('bold'))}
            {tool('Italic', <Italic className="size-4" />, () => run('italic'))}
            {tool('Underline', <Underline className="size-4" />, () => run('underline'))}
            {tool('Bulleted list', <List className="size-4" />, () => run('insertUnorderedList'))}
            {tool('Numbered list', <ListOrdered className="size-4" />, () => run('insertOrderedList'))}
            {tool('Link', <Link2 className="size-4" />, link)}
            {tool('Clear formatting', <Eraser className="size-4" />, () => run('removeFormat'))}
          </>
        )}
        <span className="flex-1" />
        {tool(source ? 'Back to the editor' : 'Edit the HTML', <Code2 className="size-4" />, () => setSource((s) => !s), source)}
      </div>

      {source ? (
        <textarea
          value={value}
          onChange={(e) => onChange(e.target.value)}
          spellCheck={false}
          style={{ minHeight }}
          className="block w-full resize-y rounded-b-xl bg-transparent p-3 font-mono text-xs text-slate-800 outline-none dark:text-slate-100"
        />
      ) : (
        <div
          ref={box}
          contentEditable
          suppressContentEditableWarning
          role="textbox"
          aria-multiline="true"
          data-placeholder={placeholder}
          onInput={(e) => onChange((e.target as HTMLDivElement).innerHTML)}
          style={{ minHeight }}
          className="mail-editor max-h-[55vh] overflow-y-auto p-3 text-sm leading-relaxed text-slate-800 outline-none dark:text-slate-100 [&_a]:text-brand-600 [&_a]:underline [&_blockquote]:border-l-2 [&_blockquote]:border-slate-300 [&_blockquote]:pl-3 [&_blockquote]:text-slate-500 [&_ol]:list-decimal [&_ol]:pl-6 [&_ul]:list-disc [&_ul]:pl-6"
        />
      )}
    </div>
  )
}
