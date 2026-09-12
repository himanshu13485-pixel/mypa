import { useEffect, useState } from 'react'
import type { CSSProperties } from 'react'
import { Check } from 'lucide-react'
import { clsx } from 'clsx'
import {
  BACKGROUNDS, BIRTHDAY_BELATED_BUILT_IN, BIRTHDAY_REPLY_BUILT_IN, BIRTHDAY_WISH_BUILT_IN, SIDEBARS, backgroundPreset,
} from '../lib/backgrounds'
import { Button, Input, Label } from './ui'

type Change = { background?: string | null; sidebar?: string | null }

/**
 * Background and sidebar swatches, for whichever level is choosing.
 *
 * With `inherited`, the first tile is "Default" and shows the look it would
 * actually give - the company's or Netvork's - so nobody picks it blind.
 * Without it (Netvork's own default, where there is nothing above), the
 * plain tile is the empty choice.
 *
 * Two columns on a phone, so a label fits under every tile.
 */
export function ThemePicker({ background, sidebar, onPick, busy, inherited }: {
  background: string | null
  sidebar: string | null
  onPick: (change: Change) => void
  busy?: boolean
  inherited?: { background: string | null; sidebar: string | null; from: string }
}) {
  const plain = inherited ? 'plain' : null
  const isPlain = (v: string | null) => (inherited ? v === 'plain' : !v || v === 'plain')
  const inheritedSidebar = SIDEBARS.find((s) => s.key === inherited?.sidebar)

  return (
    <div>
      <p className="mb-1.5 mt-3 text-xs font-medium text-slate-600 dark:text-slate-300">Background</p>
      <div className="grid grid-cols-2 gap-2 min-[420px]:grid-cols-3 md:grid-cols-5">
        {inherited && (
          <Swatch
            selected={!background}
            disabled={busy}
            onClick={() => onPick({ background: null })}
            style={{ background: backgroundPreset(inherited.background)?.light ?? '#f1f5f9' }}
            label={`Default · ${inherited.from}`}
          />
        )}
        <Swatch
          selected={isPlain(background)}
          disabled={busy}
          onClick={() => onPick({ background: plain })}
          style={{ background: '#ffffff' }}
          label="Plain"
        />
        {BACKGROUNDS.map((b) => (
          <Swatch
            key={b.key}
            selected={background === b.key}
            disabled={busy}
            onClick={() => onPick({ background: b.key })}
            style={{ background: b.light }}
            label={b.label}
          />
        ))}
      </div>

      <p className="mb-1.5 mt-4 text-xs font-medium text-slate-600 dark:text-slate-300">Sidebar</p>
      <div className="grid grid-cols-2 gap-2 min-[420px]:grid-cols-3 md:grid-cols-5">
        {inherited && (
          <Swatch
            selected={!sidebar}
            disabled={busy}
            onClick={() => onPick({ sidebar: null })}
            style={{ background: inheritedSidebar?.background ?? '#e2e8f0' }}
            label={`Default · ${inherited.from}`}
            dark={!!inheritedSidebar}
          />
        )}
        <Swatch
          selected={isPlain(sidebar)}
          disabled={busy}
          onClick={() => onPick({ sidebar: plain })}
          style={{ background: 'linear-gradient(180deg, #f8fafc 0%, #0f172a 100%)' }}
          label="Standard"
        />
        {SIDEBARS.map((sb) => (
          <Swatch
            key={sb.key}
            selected={sidebar === sb.key}
            disabled={busy}
            onClick={() => onPick({ sidebar: sb.key })}
            style={{ background: sb.background }}
            label={sb.label}
            dark
          />
        ))}
      </div>
    </div>
  )
}

function Swatch({ selected, disabled, onClick, style, label, dark }: {
  selected: boolean
  disabled?: boolean
  onClick: () => void
  style: CSSProperties
  label: string
  dark?: boolean
}) {
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      title={label}
      aria-pressed={selected}
      style={style}
      className={clsx(
        'relative flex h-16 items-end overflow-hidden rounded-xl border text-left transition-shadow disabled:opacity-60',
        selected ? 'border-emerald-500 ring-2 ring-emerald-500' : 'border-slate-200 hover:shadow-md dark:border-slate-700',
      )}
    >
      {selected && (
        <span className="absolute right-1.5 top-1.5 flex size-5 items-center justify-center rounded-full bg-emerald-500 text-white">
          <Check className="size-3" />
        </span>
      )}
      <span className={clsx(
        'w-full truncate px-1.5 py-0.5 text-[10px] sm:text-[11px]',
        dark ? 'text-white/90' : 'bg-white/70 text-slate-700',
      )}>
        {label}
      </span>
    </button>
  )
}

/**
 * The words a birthday wish and a thank-you start from.
 *
 * Empty means "use the default", and the placeholder shows what that default
 * is, so clearing a box never leaves somebody guessing what will be sent.
 */
export function BirthdayWords({ wish, reply, belated, fallbackWish, fallbackReply, fallbackBelated, busy, onSave }: {
  wish: string | null
  reply: string | null
  belated: string | null
  fallbackWish?: string | null
  fallbackReply?: string | null
  fallbackBelated?: string | null
  busy?: boolean
  onSave: (words: { wish: string | null; reply: string | null; belated: string | null }) => void
}) {
  const [draftWish, setDraftWish] = useState(wish ?? '')
  const [draftReply, setDraftReply] = useState(reply ?? '')
  const [draftBelated, setDraftBelated] = useState(belated ?? '')

  useEffect(() => { setDraftWish(wish ?? '') }, [wish])
  useEffect(() => { setDraftReply(reply ?? '') }, [reply])
  useEffect(() => { setDraftBelated(belated ?? '') }, [belated])

  const changed = draftWish.trim() !== (wish ?? '')
    || draftReply.trim() !== (reply ?? '')
    || draftBelated.trim() !== (belated ?? '')

  return (
    <div className="mt-3 space-y-3">
      <div>
        <Label>Birthday wish</Label>
        <Input
          value={draftWish}
          onChange={(e) => setDraftWish(e.target.value)}
          placeholder={fallbackWish || BIRTHDAY_WISH_BUILT_IN}
          maxLength={500}
          className="w-full"
        />
      </div>
      <div>
        <Label>Belated wish (after the day)</Label>
        <Input
          value={draftBelated}
          onChange={(e) => setDraftBelated(e.target.value)}
          placeholder={fallbackBelated || BIRTHDAY_BELATED_BUILT_IN}
          maxLength={500}
          className="w-full"
        />
      </div>
      <div>
        <Label>Thank-you</Label>
        <Input
          value={draftReply}
          onChange={(e) => setDraftReply(e.target.value)}
          placeholder={fallbackReply || BIRTHDAY_REPLY_BUILT_IN}
          maxLength={500}
          className="w-full"
        />
      </div>
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-[11px] text-slate-400">
          {'{name}'} becomes the person's name. Leave a box empty to use the default shown in it.
        </p>
        <Button
          size="sm"
          disabled={busy || !changed}
          onClick={() => onSave({
            wish: draftWish.trim() || null,
            reply: draftReply.trim() || null,
            belated: draftBelated.trim() || null,
          })}
          className="self-end sm:self-auto"
        >
          {busy ? 'Saving…' : 'Save words'}
        </Button>
      </div>
    </div>
  )
}
