import { useRef, useState } from 'react'
import { Sparkles } from 'lucide-react'
import { Button } from './ui'
import { loadImageBackground, presetBackground, type BackgroundEffect } from '../lib/videoFx'

export type BackgroundChoice = { effect: BackgroundEffect | null; label: string }

const PRESETS: { key: 'office' | 'sunset' | 'forest' | 'night'; label: string; swatch: string }[] = [
  { key: 'office', label: 'Office', swatch: 'linear-gradient(#e2e8f0,#94a3b8)' },
  { key: 'sunset', label: 'Sunset', swatch: 'linear-gradient(#fbbf24,#7c2d12)' },
  { key: 'forest', label: 'Forest', swatch: 'linear-gradient(#bbf7d0,#14532d)' },
  { key: 'night', label: 'Night', swatch: 'linear-gradient(#1e293b,#020617)' },
]

/**
 * Zoom-style background menu: None / Blur / preset scenes / your own image.
 * Calls onPick with the chosen effect (null = raw camera).
 */
export default function BackgroundPicker({
  active,
  disabled,
  busy,
  onPick,
  compact = false,
}: {
  active: string
  disabled?: boolean
  busy?: boolean
  onPick: (choice: BackgroundChoice) => void
  compact?: boolean
}) {
  const [open, setOpen] = useState(false)
  const fileRef = useRef<HTMLInputElement>(null)

  const pick = (choice: BackgroundChoice) => {
    setOpen(false)
    onPick(choice)
  }

  return (
    <div className="relative">
      <Button
        size="sm"
        variant={active !== 'none' ? 'primary' : 'secondary'}
        title={disabled ? 'Backgrounds are unavailable right now' : 'Change my background'}
        onClick={() => setOpen((o) => !o)}
        disabled={disabled || busy}
      >
        <Sparkles className={compact ? 'size-3.5' : 'size-4'} />
      </Button>
      {open && (
        <div className="absolute bottom-11 left-1/2 z-30 w-48 -translate-x-1/2 rounded-xl border border-slate-200 bg-white p-2 shadow-lg dark:border-slate-700 dark:bg-slate-900">
          <p className="mb-1.5 px-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Background</p>
          <div className="space-y-1">
            <button
              className={rowCls(active === 'none')}
              onClick={() => pick({ effect: null, label: 'none' })}
            >
              <span className="inline-block size-5 rounded border border-slate-300 dark:border-slate-600" /> None
            </button>
            <button
              className={rowCls(active === 'blur')}
              onClick={() => pick({ effect: { type: 'blur' }, label: 'blur' })}
            >
              <span className="inline-block size-5 rounded border border-slate-300 backdrop-blur dark:border-slate-600" style={{ background: 'repeating-linear-gradient(45deg,#cbd5e1,#cbd5e1 2px,#f1f5f9 2px,#f1f5f9 4px)' }} /> Blur
            </button>
            {PRESETS.map((p) => (
              <button
                key={p.key}
                className={rowCls(active === p.key)}
                onClick={() => pick({ effect: { type: 'image', image: presetBackground(p.key) }, label: p.key })}
              >
                <span className="inline-block size-5 rounded" style={{ background: p.swatch }} /> {p.label}
              </button>
            ))}
            <button className={rowCls(active === 'custom')} onClick={() => fileRef.current?.click()}>
              <span className="inline-block size-5 rounded border border-dashed border-slate-400 text-center text-[10px] leading-5">+</span> My image…
            </button>
            <input
              ref={fileRef}
              type="file"
              accept="image/*"
              className="hidden"
              onChange={async (e) => {
                const file = e.target.files?.[0]
                e.target.value = ''
                if (!file) return
                try {
                  const image = await loadImageBackground(file)
                  pick({ effect: { type: 'image', image }, label: 'custom' })
                } catch {
                  alert('Could not load that image.')
                }
              }}
            />
          </div>
        </div>
      )}
    </div>
  )
}

function rowCls(selected: boolean): string {
  return (
    'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs ' +
    (selected ? 'bg-brand-50 font-semibold text-brand-700 dark:bg-brand-950 dark:text-brand-300' : 'hover:bg-slate-50 dark:hover:bg-slate-800')
  )
}
