import { clsx } from 'clsx'
import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react'
import { AlertTriangle, Inbox, RefreshCw, X } from 'lucide-react'

/**
 * These controls default to full width, but `w-full` and a caller's `w-32` are
 * both width utilities — which one wins is decided by the order Tailwind emits
 * them, not by the order they are written, and `w-full` was winning. Every
 * `<Select className="w-32">` in the app was silently full width, which is how
 * the Add-member row ended up with a name field squeezed to nothing beside a
 * role dropdown spanning the row. So only apply the default when the caller
 * has not asked for a width of their own.
 */
function widthClass(className?: string): string | false {
  return !/(^|\s)(w-|min-w-|max-w-)\S/.test(className ?? '') && 'w-full'
}

/**
 * One description of what a form field looks like, so a text box, a dropdown
 * and a text area are visibly the same control with different contents. They
 * had drifted into three slightly different borders and focus rings.
 *
 * The outline is a ring drawn inside the box rather than a border, so a field
 * does not change size when it takes focus.
 */
const FIELD = [
  'rounded-xl bg-white px-3 py-2 text-sm text-slate-900 shadow-sm',
  'ring-1 ring-inset ring-slate-200 transition-shadow',
  'focus:outline-none focus:ring-2 focus:ring-brand-500',
  'dark:bg-slate-800 dark:text-slate-100 dark:shadow-none dark:ring-slate-700',
].join(' ')

export function Button({
  variant = 'primary',
  size = 'md',
  className,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
  size?: 'sm' | 'md'
}) {
  return (
    <button
      className={clsx(
        'inline-flex items-center justify-center gap-1.5 rounded-xl font-medium',
        // A press should feel like a press. 120ms is under the threshold where
        // a transition starts to feel like lag.
        'transition-[background-color,box-shadow,transform] duration-[120ms] active:scale-[0.98]',
        'disabled:pointer-events-none disabled:opacity-50',
        // `tap` only bites on coarse pointers, so desktop keeps its compact
        // proportions while a thumb still gets 44px to aim at.
        'tap touch-manipulation',
        size === 'sm' ? 'px-3 py-1.5 text-xs sm:px-2.5' : 'px-4 py-2 text-sm',
        // A hairline drawn *inside* the button, not a border that adds a pixel
        // to its size — so a secondary button lines up with a primary one
        // beside it instead of standing 2px taller.
        variant === 'primary' && 'bg-brand-600 text-white shadow-sm hover:bg-brand-700',
        variant === 'secondary' &&
          'bg-white text-slate-700 shadow-sm ring-1 ring-inset ring-slate-200 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-200 dark:shadow-none dark:ring-slate-700 dark:hover:bg-slate-700',
        variant === 'ghost' && 'text-slate-600 hover:bg-slate-900/5 dark:text-slate-300 dark:hover:bg-white/10',
        variant === 'danger' && 'bg-red-600 text-white shadow-sm hover:bg-red-700',
        className,
      )}
      {...props}
    />
  )
}

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return (
    <input
      className={clsx(
        FIELD,
        widthClass(className),
        'tap placeholder:text-slate-400',
        className,
      )}
      {...props}
    />
  )
}

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return (
    <textarea
      className={clsx(
        FIELD,
        widthClass(className),
        'placeholder:text-slate-400',
        className,
      )}
      {...props}
    />
  )
}

export function Select({ className, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select
      className={clsx(
        FIELD,
        widthClass(className),
        'tap',
        className,
      )}
      {...props}
    />
  )
}

export function Label({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <label className={clsx('mb-1.5 block text-[13px] font-medium text-slate-700 dark:text-slate-300', className)}>
      {children}
    </label>
  )
}

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div
      className={clsx(
        // No border. A card is told apart from the page by being lighter than
        // it and casting a shadow, which is how a piece of paper on a desk
        // works; a grey outline around every white box is what made the app
        // look like a form from 2013. The hairline ring is there only to hold
        // the edge where the shadow is too faint to read.
        'rounded-2xl bg-white p-4 shadow-card ring-1 ring-slate-900/5',
        'dark:bg-slate-900 dark:shadow-none dark:ring-white/10',
        className,
      )}
    >
      {children}
    </div>
  )
}

const badgeColors: Record<string, string> = {
  low: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
  normal: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
  medium: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300',
  high: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
  urgent: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
  critical: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
  draft: 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
  not_started: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
  planned: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
  in_progress: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
  waiting: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
  on_hold: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
  completed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  cancelled: 'bg-slate-100 text-slate-500 line-through dark:bg-slate-800 dark:text-slate-400',
  overdue: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
  archived: 'bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500',
  active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  suspended: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
  pending: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
  accepted: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  declined: 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
}

export function Badge({ value, className }: { value: string; className?: string }) {
  return (
    <span
      className={clsx(
        // A badge sits at the end of a row and states one word. Letting it
        // wrap ("Not / Started" on two lines) makes the row taller than the
        // thing it is labelling; it should shrink the title instead.
        'inline-flex shrink-0 items-center whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium capitalize',
        badgeColors[value] ?? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
        className,
      )}
    >
      {value.replaceAll('_', ' ')}
    </span>
  )
}

export function Spinner({ className }: { className?: string }) {
  return (
    <div className={clsx('flex items-center justify-center py-10', className)}>
      <div className="size-6 animate-spin rounded-full border-2 border-slate-200 border-t-brand-600 dark:border-slate-700 dark:border-t-brand-400" />
    </div>
  )
}

/**
 * What a page shows when its data could not be loaded.
 *
 * Every list in the app used to fall through to its empty state on failure,
 * so "the server is down" and "you have nothing here" looked identical — and
 * the reassuring one was the lie.
 */
export function LoadError({
  message,
  onRetry,
  what = 'this',
}: {
  message?: string | null
  onRetry?: () => void
  /** e.g. "your tasks" — reads as "Could not load your tasks." */
  what?: string
}) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 py-10 text-center">
      <AlertTriangle className="size-5 text-amber-500" />
      <p className="text-sm font-medium text-slate-600 dark:text-slate-300">Could not load {what}.</p>
      <p className="max-w-sm text-xs text-slate-400">
        {message || 'Check your connection and try again — nothing has been lost.'}
      </p>
      {onRetry && (
        <Button size="sm" variant="secondary" className="mt-1" onClick={onRetry}>
          <RefreshCw className="size-3.5" /> Try again
        </Button>
      )}
    </div>
  )
}

export function EmptyState({ title, hint }: { title: string; hint?: string }) {
  return (
    // An empty list used to be two lines of grey text adrift in white space.
    // The disc gives the eye something to land on, and says "nothing here yet"
    // rather than "something failed to load".
    <div className="flex flex-col items-center justify-center px-6 py-14 text-center">
      <span className="mb-3 flex size-11 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500">
        <Inbox className="size-5" />
      </span>
      <p className="text-sm font-medium text-slate-700 dark:text-slate-200">{title}</p>
      {hint && <p className="mt-1 max-w-xs text-xs leading-relaxed text-slate-400 dark:text-slate-500">{hint}</p>}
    </div>
  )
}

export function ErrorNote({ message }: { message: string | null }) {
  if (!message) return null
  return (
    <p className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950/40 dark:text-red-300">
      {message}
    </p>
  )
}

export function Modal({
  title,
  onClose,
  children,
  wide,
}: {
  title: string
  onClose: () => void
  children: ReactNode
  wide?: boolean
}) {
  return (
    // Bottom sheet on a phone (thumb reaches the controls, and a tall form
    // scrolls inside the sheet instead of pushing the page around); a centred
    // dialog from sm up.
    <div
      // z-80, above the floating call window at z-60. A dialog you opened is
      // always the thing you are looking at; at z-50 an active call covered
      // the bottom half of it, including its buttons.
      className="fixed inset-0 z-[80] flex items-end justify-center bg-slate-900/40 backdrop-blur-sm sm:items-start sm:overflow-y-auto sm:p-8"
      onMouseDown={(e) => e.target === e.currentTarget && onClose()}
    >
      <div
        className={clsx(
          'flex max-h-[92dvh] w-full flex-col rounded-t-3xl bg-white shadow-lift ring-1 ring-slate-900/5',
          'dark:bg-slate-900 dark:ring-white/10',
          'sm:max-h-none sm:rounded-2xl',
          wide ? 'sm:max-w-2xl' : 'sm:max-w-md',
        )}
      >
        <div className="flex shrink-0 items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-slate-800">
          <h2 className="text-base font-semibold">{title}</h2>
          <button
            onClick={onClose}
            aria-label="Close"
            className="tap -mr-2 flex items-center justify-center rounded p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"
          >
            <X className="size-5 sm:size-4" />
          </button>
        </div>
        <div className="scroll-pane pb-safe min-h-0 flex-1 overflow-y-auto p-5 sm:overflow-visible">{children}</div>
      </div>
    </div>
  )
}

/**
 * Page navigation for Laravel-paginated responses. Accepts both shapes:
 * resource collections ({ meta: {...} }) and raw paginators (top-level
 * current_page / last_page). Renders nothing while everything fits one page.
 */
export function Pager({ resp, onPage }: { resp: unknown; onPage: (page: number) => void }) {
  const r = resp as
    | { meta?: { current_page?: number; last_page?: number; total?: number }; current_page?: number; last_page?: number; total?: number }
    | null
    | undefined
  const m = r?.meta ?? r
  const current = m?.current_page
  const last = m?.last_page
  if (typeof current !== 'number' || typeof last !== 'number' || last <= 1) return null

  return (
    <div className="mt-3 flex items-center justify-between text-xs text-slate-500">
      <Button size="sm" variant="secondary" disabled={current <= 1} onClick={() => onPage(current - 1)}>
        ← Prev
      </Button>
      <span>
        Page {current} of {last}
        {typeof m?.total === 'number' && <span className="text-slate-400"> · {m.total} total</span>}
      </span>
      <Button size="sm" variant="secondary" disabled={current >= last} onClick={() => onPage(current + 1)}>
        Next →
      </Button>
    </div>
  )
}
