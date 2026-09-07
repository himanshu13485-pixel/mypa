import { useEffect, useRef, useState } from 'react'
import { clsx } from 'clsx'
import { Smile } from 'lucide-react'

/**
 * A curated set rather than the whole Unicode emoji table.
 *
 * A full emoji keyboard is a database — several thousand characters, their
 * skin-tone variants, and a search index over their names — which is a
 * dependency and a chunk of bundle weight for a feature people use to say
 * 🎉 and 😂. This is the same call the app already made for reactions
 * (QUICK_EMOJI on the message list): a short, curated set covers what a chat
 * message actually needs, and the native keyboard emoji picker — every phone
 * and every desktop OS ships one — is still one tap away for anything rarer.
 */
const CATEGORIES: { label: string; emoji: string[] }[] = [
  {
    label: 'Smileys',
    emoji: [
      '😀', '😁', '😂', '🤣', '😊', '😍', '😘', '😉', '😜', '🤗',
      '🤔', '🙄', '😏', '😴', '🥱', '😅', '😇', '🥳', '😎', '🤩',
      '😢', '😭', '😡', '😱', '😳', '🤯', '🥺', '🙃', '😬', '🤢',
    ],
  },
  {
    label: 'Gestures',
    emoji: [
      '👍', '👎', '👏', '🙏', '🤝', '👋', '✌️', '🤞', '💪', '🙌',
      '👌', '🤙', '👉', '👈', '☝️', '✋', '🫡', '🤦', '🤷', '🫶',
    ],
  },
  {
    label: 'Hearts',
    emoji: [
      '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '💔', '💕',
      '💖', '💯',
    ],
  },
  {
    label: 'Animals & nature',
    emoji: [
      '🐶', '🐱', '🐭', '🐰', '🦊', '🐻', '🐼', '🐨', '🐷', '🐸',
      '🐵', '🐔', '🐦', '🦋', '🌸', '🌻', '🌈', '☀️', '🌙', '⭐',
    ],
  },
  {
    label: 'Food',
    emoji: [
      '🍕', '🍔', '🍟', '🍎', '🍌', '🍰', '🎂', '☕', '🍵', '🍺',
      '🍻', '🥂', '🍷', '🍩', '🍫', '🍿',
    ],
  },
  {
    label: 'Activities & objects',
    emoji: [
      '🎉', '🎊', '🎁', '🎈', '🏆', '⚽', '🏀', '🎮', '🎵', '📷',
      '📱', '💻', '⏰', '💡', '🔥', '✨', '💰', '🚀', '✈️', '🚗',
    ],
  },
  {
    label: 'Symbols',
    emoji: [
      '✅', '❌', '⚠️', '❓', '❗', '💬', '🔴', '🟢', '🟡', '⚡',
    ],
  },
]

/**
 * Smile button + popover grid, for inserting an emoji into whatever is
 * being typed.
 *
 * Stays open after a pick rather than closing on the first one — saying
 * "🎉🎉🎉" is three taps on a keyboard emoji picker too, and closing after
 * the first would make every one after it a fresh click to reopen. It closes
 * on an outside click, on Escape, and after `onClose` is asked for by the
 * caller (there is none today, but a composer that unmounts under it — the
 * conversation being switched — should not leave a detached listener behind,
 * which is why the effect's cleanup does not depend on `open`).
 */
export function EmojiPicker({ onPick, className }: { onPick: (emoji: string) => void; className?: string }) {
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const onOutside = (e: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false)
    }
    const onEscape = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onOutside)
    document.addEventListener('keydown', onEscape)
    return () => {
      document.removeEventListener('mousedown', onOutside)
      document.removeEventListener('keydown', onEscape)
    }
  }, [])

  return (
    <div ref={rootRef} className={clsx('relative', className)}>
      <button
        type="button"
        className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-brand-600 dark:hover:bg-slate-800"
        title="Emoji"
        aria-label="Insert an emoji"
        onClick={() => setOpen((o) => !o)}
      >
        <Smile className="size-4" />
      </button>

      {open && (
        <div
          // Anchored above the composer, not below it: below would put the
          // panel under the keyboard on a phone, or off the bottom of the
          // window entirely in a short browser tab.
          className="absolute bottom-full left-0 z-20 mb-2 max-h-72 w-72 overflow-y-auto rounded-xl border border-slate-200 bg-white p-2 shadow-lg dark:border-slate-700 dark:bg-slate-800"
        >
          {CATEGORIES.map((cat) => (
            <div key={cat.label} className="mb-2 last:mb-0">
              <p className="mb-1 px-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                {cat.label}
              </p>
              <div className="grid grid-cols-8 gap-0.5">
                {cat.emoji.map((emoji) => (
                  <button
                    key={emoji}
                    type="button"
                    className="rounded-lg p-1 text-lg leading-none hover:bg-slate-100 dark:hover:bg-slate-700"
                    onClick={() => onPick(emoji)}
                  >
                    {emoji}
                  </button>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
