import { useEffect, useRef, useState } from 'react'
import { X } from 'lucide-react'
import { clsx } from 'clsx'
import { mails, type MailContact } from '../../../api/mails'

const looksLikeAddress = (s: string) => /^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/.test(s.replace(/^.*<([^>]+)>\s*$/, '$1').trim())

/**
 * Recipients as chips.
 *
 * An address becomes a chip on Enter, a comma, or leaving the field - and
 * a pasted list of them becomes one chip each. Anything that is not an
 * address stays red, so a typo is seen before Send rather than bounced after.
 */
export default function AddressInput({ label, value, onChange, autoFocus }: {
  label: string
  value: string[]
  onChange: (next: string[]) => void
  autoFocus?: boolean
}) {
  const [typed, setTyped] = useState('')
  /*
   * Who this might be.
   *
   * Everybody written to is remembered with the name they were written to
   * under, so two letters are usually enough - and an address typed by hand
   * is an address typed wrong sooner or later.
   */
  const [suggestions, setSuggestions] = useState<MailContact[]>([])
  const [pick, setPick] = useState(0)
  const box = useRef<HTMLLabelElement>(null)

  useEffect(() => {
    const term = typed.trim()
    if (term.length < 2) {
      setSuggestions([])

      return
    }

    let alive = true
    const timer = window.setTimeout(() => {
      mails.contacts({ q: term, suggest: true })
        .then((res) => {
          if (!alive) return
          // Never offer somebody already on the line.
          const taken = new Set(value.map((v) => v.toLowerCase()))
          setSuggestions(res.data.filter((c) => !taken.has(c.email.toLowerCase()) && !taken.has(c.label.toLowerCase())))
          setPick(0)
        })
        .catch(() => setSuggestions([]))
    }, 180)

    return () => {
      alive = false
      window.clearTimeout(timer)
    }
  }, [typed, value])

  const commit = (text: string) => {
    const parts = text.split(/[,;\n]+/).map((p) => p.trim()).filter(Boolean)
    if (!parts.length) return
    onChange([...value, ...parts.filter((p) => !value.includes(p))])
    setTyped('')
    setSuggestions([])
  }

  const take = (contact: MailContact) => {
    onChange([...value, contact.label])
    setTyped('')
    setSuggestions([])
  }

  return (
    <label ref={box} className="relative flex min-h-[40px] flex-wrap items-center gap-1.5 border-b border-slate-100 px-1 py-1 dark:border-slate-800">
      <span className="w-10 shrink-0 text-xs text-slate-400">{label}</span>
      {value.map((address) => (
        <span
          key={address}
          className={clsx(
            'flex max-w-full items-center gap-1 rounded-full px-2 py-0.5 text-xs',
            looksLikeAddress(address)
              ? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200'
              : 'bg-red-50 text-red-600 ring-1 ring-red-200 dark:bg-red-500/10 dark:text-red-300',
          )}
          title={looksLikeAddress(address) ? address : 'Not an email address'}
        >
          <span className="truncate">{address}</span>
          <button type="button" aria-label={`Remove ${address}`} onClick={() => onChange(value.filter((v) => v !== address))}>
            <X className="size-3" />
          </button>
        </span>
      ))}
      <input
        value={typed}
        autoFocus={autoFocus}
        onChange={(e) => {
          const v = e.target.value
          if (/[,;]\s*$/.test(v)) commit(v)
          else setTyped(v)
        }}
        onKeyDown={(e) => {
          if (suggestions.length && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            e.preventDefault()
            setPick((i) => (i + (e.key === 'ArrowDown' ? 1 : suggestions.length - 1)) % suggestions.length)
          } else if (e.key === 'Enter' || e.key === 'Tab') {
            // The highlighted suggestion, if one is; otherwise what was typed.
            if (suggestions[pick]) {
              e.preventDefault()
              take(suggestions[pick])
            } else if (typed.trim()) {
              e.preventDefault()
              commit(typed)
            }
          } else if (e.key === 'Escape' && suggestions.length) {
            setSuggestions([])
          } else if (e.key === 'Backspace' && !typed && value.length) {
            onChange(value.slice(0, -1))
          }
        }}
        onBlur={() => commit(typed)}
        onPaste={(e) => {
          const text = e.clipboardData.getData('text')
          if (/[,;\n]/.test(text)) {
            e.preventDefault()
            commit(text)
          }
        }}
        className="min-w-[8rem] flex-1 bg-transparent py-1 text-sm text-slate-800 outline-none dark:text-slate-100"
      />

      {suggestions.length > 0 && (
        <div className="absolute left-0 top-full z-30 mt-1 w-full max-w-md overflow-hidden rounded-xl bg-white shadow-lift ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
          {suggestions.map((contact, i) => (
            <button
              key={contact.uuid}
              type="button"
              onMouseEnter={() => setPick(i)}
              // The press must not take focus, or the field blurs and
              // commits what was half typed before the click lands.
              onMouseDown={(e) => { e.preventDefault(); take(contact) }}
              className={clsx(
                'flex w-full items-baseline gap-2 px-3 py-1.5 text-left text-sm',
                i === pick ? 'bg-slate-100 dark:bg-slate-700' : 'hover:bg-slate-50 dark:hover:bg-slate-700/60',
              )}
            >
              {contact.name && <span className="font-medium text-slate-800 dark:text-slate-100">{contact.name}</span>}
              <span className="truncate text-slate-500">{contact.email}</span>
            </button>
          ))}
        </div>
      )}
    </label>
  )
}
