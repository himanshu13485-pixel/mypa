import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { api } from '../api/client'
import { Input } from './ui'

interface Suggestion {
  uuid: string
  name: string
  username: string | null
  email: string | null
  app_id?: string | null
  /** Already one of your connections — shown first and labelled. */
  connected?: boolean
}

/**
 * Identifier input with typeahead over anyone you are allowed to reach:
 * your connections first, then other discoverable people, matched on name,
 * username, email or App ID. Picking one fills the field with a handle the
 * server can resolve. With `multi`, the field is comma-separated and
 * suggestions apply to the last segment.
 *
 * The list is rendered through a portal at fixed coordinates rather than as
 * an absolutely-positioned child: these inputs live inside modals and
 * scrolling sheets, and any `overflow` on an ancestor would otherwise clip
 * the suggestions to nothing.
 */
export default function UserSuggest({
  value,
  onChange,
  placeholder,
  multi = false,
  autoFocus = false,
  required = false,
  className,
  onEnter,
}: {
  value: string
  onChange: (value: string) => void
  placeholder?: string
  multi?: boolean
  autoFocus?: boolean
  required?: boolean
  className?: string
  onEnter?: () => void
}) {
  const [suggestions, setSuggestions] = useState<Suggestion[]>([])
  const [open, setOpen] = useState(false)
  const [searched, setSearched] = useState(false)
  const [highlight, setHighlight] = useState(0)
  const [rect, setRect] = useState<{ left: number; top: number; width: number; above: boolean } | null>(null)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const boxRef = useRef<HTMLDivElement>(null)
  const listRef = useRef<HTMLDivElement>(null)

  const activeTerm = () => {
    if (!multi) return value.trim()
    const parts = value.split(',')
    return parts[parts.length - 1].trim()
  }

  // Debounced lookup of the active term.
  useEffect(() => {
    const term = activeTerm()
    if (timerRef.current) clearTimeout(timerRef.current)
    if (term.length < 1) {
      setSuggestions([])
      setSearched(false)
      setOpen(false)
      return
    }
    timerRef.current = setTimeout(() => {
      api
        .get<{ data: Suggestion[] }>('/connections/suggest', { params: { q: term } })
        .then((r) => {
          setSuggestions(r.data.data)
          setSearched(true)
          // Stay open even with no matches: an empty dropdown that silently
          // does nothing is why this box felt broken.
          setOpen(true)
          setHighlight(0)
        })
        .catch(() => {
          setSuggestions([])
          setSearched(true)
        })
    }, 250)
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value])

  /**
   * Position the portalled list against the input, flipping above it when
   * the keyboard or the bottom of the screen leaves no room below.
   */
  useLayoutEffect(() => {
    if (!open) return
    const place = () => {
      const input = boxRef.current?.querySelector('input')
      if (!input) return
      const r = input.getBoundingClientRect()
      const wanted = Math.min(280, Math.max(56, suggestions.length * 52 + 8))
      const below = window.innerHeight - r.bottom
      setRect({
        left: r.left,
        top: below < wanted && r.top > below ? r.top - wanted - 4 : r.bottom + 4,
        width: r.width,
        above: below < wanted && r.top > below,
      })
    }
    place()
    window.addEventListener('resize', place)
    // Any ancestor scroll moves the input, so track it on the capture phase.
    window.addEventListener('scroll', place, true)
    return () => {
      window.removeEventListener('resize', place)
      window.removeEventListener('scroll', place, true)
    }
  }, [open, suggestions.length, value])

  // Close on outside click — the list is portalled, so it is not a DOM
  // descendant of the box and has to be checked separately.
  useEffect(() => {
    const onDoc = (e: MouseEvent) => {
      const target = e.target as Node
      if (boxRef.current?.contains(target) || listRef.current?.contains(target)) return
      setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [])

  const pick = (s: Suggestion) => {
    // App ID before email: it always resolves server-side, and a stranger's
    // email is not returned at all.
    const handle = s.username ?? s.app_id ?? s.email ?? s.name
    if (multi) {
      const parts = value.split(',')
      parts[parts.length - 1] = ` ${handle}`
      onChange(parts.join(',').replace(/^ /, '') + ', ')
    } else {
      onChange(handle)
    }
    setOpen(false)
  }

  return (
    <div ref={boxRef} className="relative">
      <Input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        autoFocus={autoFocus}
        required={required}
        className={className}
        autoComplete="off"
        onKeyDown={(e) => {
          if (open && e.key === 'ArrowDown') {
            e.preventDefault()
            setHighlight((h) => Math.min(h + 1, suggestions.length - 1))
          } else if (open && e.key === 'ArrowUp') {
            e.preventDefault()
            setHighlight((h) => Math.max(h - 1, 0))
          } else if (open && (e.key === 'Enter' || e.key === 'Tab')) {
            if (suggestions[highlight]) {
              e.preventDefault()
              pick(suggestions[highlight])
            }
          } else if (e.key === 'Escape') {
            setOpen(false)
          } else if (e.key === 'Enter' && !open && onEnter) {
            e.preventDefault()
            onEnter()
          }
        }}
      />
      {open && rect && createPortal(
        <div
          ref={listRef}
          className="fixed z-[60] max-h-70 overflow-y-auto overscroll-contain rounded-lg border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900"
          style={{ left: rect.left, top: rect.top, width: rect.width }}
        >
          {suggestions.length === 0 ? (
            searched && (
              <p className="px-3 py-2.5 text-xs text-slate-400">
                Nobody matches “{activeTerm()}”. You can still type an exact username, email or App ID.
              </p>
            )
          ) : (
            suggestions.map((s, i) => (
              <button
                key={s.uuid}
                type="button"
                className={
                  'flex w-full items-center gap-2 px-3 py-2 text-left text-sm ' +
                  (i === highlight ? 'bg-brand-50 dark:bg-brand-950' : 'hover:bg-slate-50 dark:hover:bg-slate-800')
                }
                onMouseEnter={() => setHighlight(i)}
                onClick={() => pick(s)}
              >
                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-100 text-[10px] font-semibold text-brand-700 dark:bg-brand-950 dark:text-brand-300">
                  {s.name.charAt(0)}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-medium">{s.name}</span>
                  <span className="block truncate text-[11px] text-slate-400">
                    {[s.username ? `@${s.username}` : null, s.email, s.app_id].filter(Boolean).join(' · ')}
                  </span>
                </span>
                {!s.connected && (
                  <span className="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                    not connected
                  </span>
                )}
              </button>
            ))
          )}
        </div>,
        document.body,
      )}
    </div>
  )
}

/** Small modal wrapper for the prompt() share flows (files, new chat). */
export function PickUserModal({
  title,
  actionLabel,
  onClose,
  onSubmit,
}: {
  title: string
  actionLabel: string
  onClose: () => void
  onSubmit: (identifier: string) => void
}) {
  const [value, setValue] = useState('')

  const submit = () => {
    if (value.trim()) {
      onSubmit(value.trim().replace(/,\s*$/, ''))
      onClose()
    }
  }

  return (
    // z-80: this one is opened *from* the call window (z-60), so at z-50 the
    // call drew straight over the dialog asking who to ring.
    <div className="fixed inset-0 z-[80] flex items-start justify-center bg-black/40 p-4 pt-24" onClick={onClose}>
      <div
        className="w-full max-w-sm rounded-xl border border-slate-200 bg-white p-4 shadow-xl dark:border-slate-800 dark:bg-slate-900"
        onClick={(e) => e.stopPropagation()}
      >
        <p className="mb-2 text-sm font-semibold">{title}</p>
        <UserSuggest value={value} onChange={setValue} placeholder="username or email" autoFocus onEnter={submit} />
        <div className="mt-3 flex justify-end gap-2">
          <button className="rounded-lg px-3 py-1.5 text-sm text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" onClick={onClose}>
            Cancel
          </button>
          <button
            className="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50"
            disabled={!value.trim()}
            onClick={submit}
          >
            {actionLabel}
          </button>
        </div>
      </div>
    </div>
  )
}
