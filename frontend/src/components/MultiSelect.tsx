import { useEffect, useRef, useState } from 'react'
import { ChevronDown, Search } from 'lucide-react'
import { clsx } from 'clsx'

export interface MultiOption {
  value: string
  label: string
}

/**
 * A dropdown of checkboxes, for a filter that can hold several values.
 *
 * `value` null means everything - every box ticked, which is the default and
 * sends no filter at all. An array is exactly those values; an empty array is
 * nothing ticked, which shows nothing. The button says which of the three it
 * is ("All", one name, "3 of 4"), and turns brand-coloured the moment it is
 * narrowing, so a filtered list never looks like the whole one.
 *
 * Long lists get a search box; every row has "only" for the common case of
 * wanting one thing out of many.
 */
export function MultiSelect({ label, options, value, onChange, className, allLabel = 'All' }: {
  label: string
  options: MultiOption[]
  value: string[] | null
  onChange: (next: string[] | null) => void
  className?: string
  allLabel?: string
}) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const box = useRef<HTMLDivElement>(null)

  const selected = value ?? options.map((o) => o.value)
  const isAll = value === null || (options.length > 0 && options.every((o) => selected.includes(o.value)))

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => {
      if (box.current && !box.current.contains(e.target as Node)) setOpen(false)
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  // Ticking the last box back is "all" again, not a list that happens to be complete.
  const commit = (next: string[]) =>
    onChange(options.length > 0 && options.every((o) => next.includes(o.value)) ? null : next)

  const toggle = (v: string) => commit(selected.includes(v) ? selected.filter((x) => x !== v) : [...selected, v])

  const needle = query.trim().toLowerCase()
  const shown = needle ? options.filter((o) => o.label.toLowerCase().includes(needle)) : options

  const summary = isAll
    ? allLabel
    : selected.length === 0
      ? 'None'
      : selected.length === 1
        ? options.find((o) => o.value === selected[0])?.label ?? '1 selected'
        : `${selected.length} of ${options.length}`

  return (
    <div ref={box} className={clsx('relative', className)}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        aria-haspopup="listbox"
        className={clsx(
          'tap flex w-full items-center gap-1.5 rounded-xl bg-white px-3 py-2 text-left text-sm shadow-sm ring-1 ring-inset transition-shadow',
          'focus:outline-none focus:ring-2 focus:ring-brand-500 dark:bg-slate-800 dark:shadow-none',
          isAll
            ? 'text-slate-800 ring-slate-200 dark:text-slate-100 dark:ring-slate-700'
            : 'text-brand-700 ring-brand-400 dark:text-brand-300 dark:ring-brand-500/60',
        )}
      >
        {/*
          * Two lines on a phone, one on a desk.
          *
          * Side by side there is room for "Assigned:" and about four letters
          * of the answer, so every chip read "Ev…" - the label taking the
          * width and the value, which is the part being asked about, losing
          * it. Stacked, both fit.
          */}
        <span className="min-w-0 flex-1">
          <span className="block truncate text-[11px] leading-tight text-slate-400 sm:inline sm:text-sm sm:leading-normal">
            {label}:
          </span>
          <span className="block truncate font-medium leading-tight sm:ml-1 sm:inline sm:leading-normal">
            {summary}
          </span>
        </span>
        <ChevronDown className={clsx('size-4 shrink-0 text-slate-400 transition-transform', open && 'rotate-180')} />
      </button>

      {open && (
        <div className="absolute left-0 z-30 mt-1 w-64 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl bg-white shadow-lift ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
          {options.length > 8 && (
            <div className="relative border-b border-slate-100 p-2 dark:border-slate-800">
              <Search className="pointer-events-none absolute left-4 top-1/2 size-3.5 -translate-y-1/2 text-slate-400" />
              <input
                autoFocus
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder={`Find ${label.toLowerCase()}…`}
                className="w-full rounded-lg bg-slate-50 py-1.5 pl-7 pr-2 text-sm text-slate-800 outline-none ring-1 ring-inset ring-slate-200 focus:ring-brand-500 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700"
              />
            </div>
          )}
          <div className="flex items-center justify-between border-b border-slate-100 px-3 py-1.5 text-xs dark:border-slate-800">
            <button type="button" onClick={() => onChange(null)} className="font-medium text-emerald-600 hover:underline">
              Select all
            </button>
            <button type="button" onClick={() => onChange([])} className="text-slate-500 hover:underline dark:text-slate-400">
              Clear
            </button>
          </div>
          <ul role="listbox" aria-multiselectable className="max-h-64 overflow-y-auto overscroll-contain py-1">
            {shown.map((o) => (
              <li key={o.value}>
                <label className="group flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800">
                  <input
                    type="checkbox"
                    checked={selected.includes(o.value)}
                    onChange={() => toggle(o.value)}
                    className="size-4 shrink-0 accent-emerald-600"
                  />
                  <span className="min-w-0 flex-1 truncate" title={o.label}>{o.label}</span>
                  <button
                    type="button"
                    onClick={(e) => { e.preventDefault(); commit([o.value]) }}
                    className="shrink-0 text-[11px] text-slate-400 opacity-0 hover:text-emerald-600 focus:opacity-100 group-hover:opacity-100"
                  >
                    only
                  </button>
                </label>
              </li>
            ))}
            {shown.length === 0 && <li className="px-3 py-2 text-xs text-slate-400">Nothing matches.</li>}
          </ul>
        </div>
      )}
    </div>
  )
}
