import { useEffect, useRef, useState } from 'react'
import { api } from '../api/client'
import { Input } from './ui'

interface Suggestion {
  uuid: string
  name: string
  username: string | null
  email: string | null
}

/**
 * Identifier input with connection typeahead: as you type, it suggests
 * matching people from YOUR connections (name / username / email). Picking
 * one fills the field with their username (or email). With `multi`, the
 * field is comma-separated and suggestions apply to the last segment.
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
  const [highlight, setHighlight] = useState(0)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const boxRef = useRef<HTMLDivElement>(null)

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
      setOpen(false)
      return
    }
    timerRef.current = setTimeout(() => {
      api
        .get<{ data: Suggestion[] }>('/connections/suggest', { params: { q: term } })
        .then((r) => {
          setSuggestions(r.data.data)
          setOpen(r.data.data.length > 0)
          setHighlight(0)
        })
        .catch(() => setSuggestions([]))
    }, 250)
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value])

  // Close on outside click.
  useEffect(() => {
    const onDoc = (e: MouseEvent) => {
      if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [])

  const pick = (s: Suggestion) => {
    const handle = s.username ?? s.email ?? s.name
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
      {open && (
        <div className="absolute z-30 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
          {suggestions.map((s, i) => (
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
              <span className="min-w-0">
                <span className="block truncate font-medium">{s.name}</span>
                <span className="block truncate text-[11px] text-slate-400">
                  {s.username ? `@${s.username}` : ''}{s.username && s.email ? ' · ' : ''}{s.email ?? ''}
                </span>
              </span>
            </button>
          ))}
        </div>
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
    <div className="fixed inset-0 z-50 flex items-start justify-center bg-black/40 p-4 pt-24" onClick={onClose}>
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
