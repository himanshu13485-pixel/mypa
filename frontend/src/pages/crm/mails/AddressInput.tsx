import { useState } from 'react'
import { X } from 'lucide-react'
import { clsx } from 'clsx'

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

  const commit = (text: string) => {
    const parts = text.split(/[,;\n]+/).map((p) => p.trim()).filter(Boolean)
    if (!parts.length) return
    onChange([...value, ...parts.filter((p) => !value.includes(p))])
    setTyped('')
  }

  return (
    <label className="flex min-h-[40px] flex-wrap items-center gap-1.5 border-b border-slate-100 px-1 py-1 dark:border-slate-800">
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
          if (e.key === 'Enter' || e.key === 'Tab') {
            if (typed.trim()) {
              e.preventDefault()
              commit(typed)
            }
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
    </label>
  )
}
